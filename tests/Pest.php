<?php

declare(strict_types=1);

use App\Domain\Money\Currency;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case bindings
|--------------------------------------------------------------------------
|
| Feature tests hit a real PostgreSQL database and a real Redis instance,
| because the behaviour under test lives in those engines rather than in PHP.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        // Redis is not transactional and survives RefreshDatabase rollbacks,
        // so leftover EAT or lock keys would leak between tests and make
        // single-use assertions pass or fail depending on execution order.
        Redis::flushdb();
    })
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

// Deliberately WITHOUT RefreshDatabase: the concurrency suite talks to a
// separate running server over HTTP, which cannot see an uncommitted test
// transaction. It manages its own fixtures and cleans up after itself.
pest()->extend(TestCase::class)->in('Stress');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Current valid TOTP code for a base32 secret.
 */
function currentTotp(string $secret): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}

/**
 * Create the house liquidity wallets every swap needs as a counterparty.
 *
 * @return array<string, Wallet>
 */
function seedSystemWallets(): array
{
    $wallets = [];

    foreach (Currency::cases() as $currency) {
        $wallets[$currency->value] = Wallet::query()->firstOrCreate(
            ['currency' => $currency->value, 'type' => Wallet::TYPE_SYSTEM],
            ['user_id' => null],
        );
    }

    return $wallets;
}

/**
 * Credit a user wallet through a real balanced posting.
 *
 * Tests must not fabricate balances by any other route: there is no balance
 * column, and money that did not arrive via double entry would not exist.
 */
function fundWallet(User $user, Currency $currency, int $subunits): Wallet
{
    $system = Wallet::query()->firstOrCreate(
        ['currency' => $currency->value, 'type' => Wallet::TYPE_SYSTEM],
        ['user_id' => null],
    );

    $wallet = Wallet::query()->firstOrCreate(
        ['user_id' => $user->id, 'currency' => $currency->value],
        ['type' => Wallet::TYPE_USER],
    );

    if ($subunits <= 0) {
        return $wallet;
    }

    DB::transaction(function () use ($user, $system, $wallet, $subunits, $currency): void {
        $transaction = LedgerTransaction::create([
            'reference' => 'test-funding:'.Str::uuid(),
            'type' => LedgerTransaction::TYPE_FUNDING,
            'status' => LedgerTransaction::STATUS_COMPLETED,
            'user_id' => $user->id,
            'metadata' => ['source' => 'test'],
        ]);

        LedgerEntry::create([
            'transaction_id' => $transaction->id,
            'wallet_id' => $system->id,
            'direction' => LedgerEntry::DIRECTION_DEBIT,
            'amount' => $subunits,
            'currency' => $currency->value,
        ]);

        LedgerEntry::create([
            'transaction_id' => $transaction->id,
            'wallet_id' => $wallet->id,
            'direction' => LedgerEntry::DIRECTION_CREDIT,
            'amount' => $subunits,
            'currency' => $currency->value,
        ]);
    });

    return $wallet->refresh();
}

/**
 * Pin the exchange rate so conversion assertions are exact rather than
 * dependent on the mock provider's jitter.
 */
function pinRate(string $rate = '0.005'): void
{
    Redis::set(
        (string) config('tupay.rates.cache_key'),
        json_encode(['rate' => $rate, 'fetched_at' => now()->getTimestamp()], JSON_THROW_ON_ERROR),
        'EX',
        (int) config('tupay.rates.stale_ttl'),
    );
}

/**
 * Net movement per currency across the entire ledger. Must always be zero.
 *
 * @return array<string, int>
 */
function ledgerNetByCurrency(): array
{
    return DB::table('ledger_entries')
        ->selectRaw('currency, SUM(signed_amount) AS net')
        ->groupBy('currency')
        ->pluck('net', 'currency')
        ->map(fn ($n): int => (int) $n)
        ->all();
}
