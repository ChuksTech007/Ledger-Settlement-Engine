<?php

declare(strict_types=1);

namespace App\Services\Swap;

use App\Domain\Ledger\LedgerException;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Swap\SlippageCalculator;
use App\Domain\Swap\SwapQuote;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Rates\RateProvider;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Executes a cross-currency swap under the locking discipline the spec sets out.
 *
 * ORDER OF OPERATIONS (deliberate, and the reason this is safe under load):
 *
 *   1. Acquire Redis locks on the user id and both wallet ids, sorted
 *      alphabetically — a total order, so no deadlock cycle can form.
 *   2. Open a SQL transaction at REPEATABLE READ.
 *   3. SELECT ... FOR UPDATE the participating wallet rows, so concurrent
 *      swaps serialise inside the database rather than racing.
 *   4. Validate the balance, post the double-entry legs, commit.
 *   5. Release the Redis locks in a finally block, unconditionally.
 *
 * Steps 1 and 3 are not redundant. Redis rejects contenders cheaply and gives
 * a fast 409 without touching the database; the row lock is the authority that
 * still holds if Redis is unavailable, restarted, or a lock TTL lapses mid-swap.
 * Belt and braces, in that order, because the cheap check should come first.
 */
final class SwapService
{
    public function __construct(
        private readonly RateProvider $rates,
        private readonly SlippageCalculator $slippage,
    ) {}

    /**
     * @throws LedgerException
     */
    public function execute(User $user, Currency $source, Currency $target, Money $amount): LedgerTransaction
    {
        if ($source === $target) {
            throw LedgerException::insufficientFunds('Source and target currencies must differ.');
        }

        $sourceWallet = $user->walletFor($source) ?? throw LedgerException::walletNotFound($source->value);
        $targetWallet = $user->walletFor($target) ?? throw LedgerException::walletNotFound($target->value);

        $systemSource = $this->systemWallet($source);
        $systemTarget = $this->systemWallet($target);

        $lock = new DistributedLock;

        // Sorted inside acquireAll(); listed here in logical order for clarity.
        $lock->acquireAll([
            'user:'.$user->id,
            'wallet:'.$sourceWallet->id,
            'wallet:'.$targetWallet->id,
        ]);

        try {
            return $this->postWithinTransaction(
                $user, $amount, $sourceWallet, $targetWallet, $systemSource, $systemTarget, $source, $target,
            );
        } finally {
            // Unconditional: an exception, a serialization abort, or success
            // must all leave the wallet unlocked.
            $lock->releaseAll();
        }
    }

    /**
     * @throws LedgerException
     */
    private function postWithinTransaction(
        User $user,
        Money $amount,
        Wallet $sourceWallet,
        Wallet $targetWallet,
        Wallet $systemSource,
        Wallet $systemTarget,
        Currency $source,
        Currency $target,
    ): LedgerTransaction {
        try {
            /** @var LedgerTransaction $transaction */
            $transaction = DB::transaction(function () use (
                $user, $amount, $sourceWallet, $targetWallet, $systemSource, $systemTarget, $source, $target
            ): LedgerTransaction {
                // REPEATABLE READ gives a stable snapshot for the duration, so
                // the balance we validate is the balance we post against.
                //
                // PostgreSQL requires this to be the first statement in the
                // transaction, and it cannot be applied to a SAVEPOINT. Under
                // RefreshDatabase the suite holds an outer transaction and ours
                // is nested, so we only issue it when we are genuinely the root
                // transaction. In production that is always true; in tests the
                // nested case falls back to the connection default and the
                // FOR UPDATE row locks below still carry the correctness.
                if (DB::transactionLevel() === 1) {
                    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                }

                // Pessimistic row-level lock on the participating accounts,
                // taken in a stable id order for the same anti-deadlock reason
                // the Redis keys are sorted.
                $walletIds = [$sourceWallet->id, $targetWallet->id, $systemSource->id, $systemTarget->id];
                sort($walletIds, SORT_STRING);

                Wallet::query()
                    ->whereIn('id', $walletIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                // Read the balance only after the rows are locked, otherwise
                // ten concurrent callers all read the same pre-debit figure and
                // each independently concludes it can afford the swap.
                $available = $sourceWallet->lockedBalance();

                if ($available->isLessThan($amount)) {
                    throw LedgerException::insufficientFunds(sprintf(
                        'Insufficient funds: balance %d %s, requested %d.',
                        $available->subunits,
                        $source->subunitName(),
                        $amount->subunits,
                    ));
                }

                $quote = $this->quote($amount, $source, $target);

                if ($quote->target->isZero()) {
                    throw LedgerException::amountTooSmall();
                }

                $transaction = LedgerTransaction::create([
                    // Unique per attempt. Idempotency for swaps is provided by
                    // the single-use EAT upstream, not by collapsing distinct
                    // requests here — two deliberate identical swaps are two
                    // legitimate swaps.
                    'reference' => 'swap:'.Str::uuid(),
                    'type' => LedgerTransaction::TYPE_SWAP,
                    'status' => LedgerTransaction::STATUS_COMPLETED,
                    'user_id' => $user->id,
                    'metadata' => $quote->toMetadata(),
                ]);

                // Four legs, netting to zero within each currency.
                $this->post($transaction, $sourceWallet, LedgerEntry::DIRECTION_DEBIT, $quote->grossSource);
                $this->post($transaction, $systemSource, LedgerEntry::DIRECTION_CREDIT, $quote->grossSource);
                $this->post($transaction, $systemTarget, LedgerEntry::DIRECTION_DEBIT, $quote->target);
                $this->post($transaction, $targetWallet, LedgerEntry::DIRECTION_CREDIT, $quote->target);

                return $transaction;
            }, attempts: 1);

            return $transaction;
        } catch (QueryException $e) {
            throw $this->translate($e);
        }
    }

    /**
     * Translate PostgreSQL error states into the statuses the spec requires.
     */
    private function translate(QueryException $e): LedgerException
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $message = $e->getMessage();

        // 40001 serialization_failure, 40P01 deadlock_detected. Both mean the
        // database aborted us to protect isolation: semantically, we lost a
        // race, which is a 409.
        if (in_array($sqlState, ['40001', '40P01'], true)) {
            return LedgerException::serializationFailure();
        }

        // Raised by the non-negative balance trigger.
        if (str_contains($message, 'insufficient_funds')) {
            return LedgerException::insufficientFunds();
        }

        if (str_contains($message, 'unbalanced_transaction')) {
            return LedgerException::insufficientFunds('The posting did not balance.');
        }

        throw $e;
    }

    private function quote(Money $amount, Currency $source, Currency $target): SwapQuote
    {
        $quoted = $this->rates->quote($source, $target);

        $fee = $this->slippage->feeFor($amount);
        $bps = $this->slippage->basisPointsFor($amount);
        $net = $amount->minus($fee);

        $converted = $net->convertTo($target, $quoted['rate'], (int) config('tupay.rates.scale', 18));

        return new SwapQuote(
            grossSource: $amount,
            fee: $fee,
            netSource: $net,
            target: $converted,
            rate: $quoted['rate'],
            slippageBps: $bps,
            rateWasStale: (bool) $quoted['stale'],
        );
    }

    private function post(LedgerTransaction $transaction, Wallet $wallet, string $direction, Money $money): void
    {
        LedgerEntry::create([
            'transaction_id' => $transaction->id,
            'wallet_id' => $wallet->id,
            'direction' => $direction,
            'amount' => $money->subunits,
            'currency' => $money->currency->value,
        ]);
    }

    private function systemWallet(Currency $currency): Wallet
    {
        $wallet = Wallet::query()
            ->where('type', Wallet::TYPE_SYSTEM)
            ->where('currency', $currency->value)
            ->first();

        return $wallet ?? throw LedgerException::liquidityUnavailable($currency->value);
    }
}
