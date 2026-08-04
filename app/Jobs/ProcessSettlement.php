<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Money\Currency;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\SettlementEvent;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts a completed settlement to the ledger, off the request path.
 *
 * ShouldBeUnique plus the ledger_posted_at check gives two independent guards
 * against double-crediting: the queue refuses to hold two jobs for the same
 * settlement concurrently, and the row itself records whether the money has
 * already moved. The second matters because uniqueness locks expire, and a
 * retried job after an expiry must still be a no-op.
 */
final class ProcessSettlement implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [1, 5, 15, 30];

    public function __construct(
        public readonly string $settlementEventId,
    ) {}

    public function uniqueId(): string
    {
        return $this->settlementEventId;
    }

    public function handle(): void
    {
        DB::transaction(function (): void {
            /** @var SettlementEvent|null $event */
            $event = SettlementEvent::query()
                ->where('id', $this->settlementEventId)
                ->lockForUpdate()
                ->first();

            if ($event === null) {
                return;
            }

            // The decisive idempotency check, taken under a row lock: if the
            // money already moved, this delivery is a repeat and must do
            // nothing at all.
            if ($event->alreadyPosted()) {
                return;
            }

            if (! $event->status()->shouldPostToLedger()) {
                return;
            }

            $currency = Currency::from((string) $event->currency);
            $amount = (int) $event->amount;

            $user = User::query()->findOrFail($event->user_id);

            $userWallet = $user->walletFor($currency)
                ?? throw new RuntimeException("User {$user->id} has no {$currency->value} wallet.");

            $systemWallet = Wallet::query()
                ->where('type', Wallet::TYPE_SYSTEM)
                ->where('currency', $currency->value)
                ->firstOrFail();

            $transaction = LedgerTransaction::create([
                'reference' => 'settlement:'.$event->provider_reference,
                'type' => LedgerTransaction::TYPE_SETTLEMENT,
                'status' => LedgerTransaction::STATUS_COMPLETED,
                'user_id' => $user->id,
                'metadata' => [
                    'provider_reference' => $event->provider_reference,
                    'amount_subunits' => $amount,
                    'currency' => $currency->value,
                ],
            ]);

            // Balanced pair: the house pays, the user receives.
            LedgerEntry::create([
                'transaction_id' => $transaction->id,
                'wallet_id' => $systemWallet->id,
                'direction' => LedgerEntry::DIRECTION_DEBIT,
                'amount' => $amount,
                'currency' => $currency->value,
            ]);

            LedgerEntry::create([
                'transaction_id' => $transaction->id,
                'wallet_id' => $userWallet->id,
                'direction' => LedgerEntry::DIRECTION_CREDIT,
                'amount' => $amount,
                'currency' => $currency->value,
            ]);

            $event->transaction_id = $transaction->id;
            $event->ledger_posted_at = now();
            $event->save();
        });
    }
}
