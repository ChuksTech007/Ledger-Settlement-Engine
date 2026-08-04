<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Money\Currency;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Test fixtures for the assessment.
 *
 * TOTP secrets are FIXED rather than random so a reviewer can generate valid
 * codes from the README without first querying the database. The current code
 * is printed on seed for the same reason.
 *
 * Balances are established by posting real double-entry funding transactions,
 * not by writing a balance column — there is no balance column to write, and
 * seeding through the ledger proves the derived-balance design end to end.
 */
class DatabaseSeeder extends Seeder
{
    /** Deterministic base32 secrets so codes can be generated from the README. */
    private const TRADER_SECRET = 'KRSXG5CTMVRXEZLUGE3TCNZUGM2TSMBQ';

    private const WHALE_SECRET = 'MFRGGZDFMZTWQ2LKNNWG23TPOBYXE43U';

    private const THIN_SECRET = 'NB2W45DFOIZA4Y3PNUXGY2LOMFZWKZBA';

    public function run(): void
    {
        $google2fa = new Google2FA;

        // --- House liquidity ------------------------------------------------
        // System wallets are the counterparty to every user movement. They are
        // exempt from the non-negative trigger by design: the house carries the
        // negative position that a user's positive balance implies.
        $systemWallets = [];

        foreach (Currency::cases() as $currency) {
            $systemWallets[$currency->value] = Wallet::query()->firstOrCreate(
                ['currency' => $currency->value, 'type' => Wallet::TYPE_SYSTEM],
                ['user_id' => null],
            );
        }

        // --- Test users -----------------------------------------------------
        $fixtures = [
            [
                'name' => 'Ada Trader',
                'email' => 'trader@tupay.test',
                'secret' => self::TRADER_SECRET,
                // 5,000,000.00 NGN and 20,000.00 CNY
                'balances' => ['NGN' => 500_000_000, 'CNY' => 2_000_000],
                'note' => 'General purpose. Comfortably funded.',
            ],
            [
                'name' => 'Bode Whale',
                'email' => 'whale@tupay.test',
                'secret' => self::WHALE_SECRET,
                // 50,000,000.00 NGN — crosses several slippage tiers.
                'balances' => ['NGN' => 5_000_000_000, 'CNY' => 0],
                'note' => 'Exercises tiered slippage above 1,000,000 NGN.',
            ],
            [
                'name' => 'Chidi Thin',
                'email' => 'thin@tupay.test',
                'secret' => self::THIN_SECRET,
                // Exactly 10,000.00 NGN: funds precisely ONE 10,000 NGN swap.
                // This is the account the concurrency stress test targets.
                'balances' => ['NGN' => 1_000_000, 'CNY' => 0],
                'note' => 'Funds exactly one swap. Used by the race test.',
            ],
        ];

        $rows = [];

        foreach ($fixtures as $fixture) {
            /** @var User $user */
            $user = User::query()->updateOrCreate(
                ['email' => $fixture['email']],
                [
                    'name' => $fixture['name'],
                    'password' => 'password',
                    'email_verified_at' => now(),
                    'totp_secret' => $fixture['secret'],
                    'totp_confirmed_at' => now(),
                ],
            );

            foreach (Currency::cases() as $currency) {
                $wallet = Wallet::query()->firstOrCreate(
                    ['user_id' => $user->id, 'currency' => $currency->value],
                    ['type' => Wallet::TYPE_USER],
                );

                $target = (int) ($fixture['balances'][$currency->value] ?? 0);
                $delta = $target - $wallet->balance()->subunits;

                if ($delta > 0) {
                    $this->fund($user->id, $systemWallets[$currency->value], $wallet, $delta, $currency);
                }
            }

            $rows[] = [
                $fixture['email'],
                $fixture['secret'],
                $google2fa->getCurrentOtp($fixture['secret']),
                $fixture['note'],
            ];
        }

        $this->command?->newLine();
        $this->command?->info('Seeded test users (password for all: "password")');
        $this->command?->table(['Email', 'TOTP secret', 'Code right now', 'Purpose'], $rows);
        $this->command?->warn('TOTP codes rotate every 30s — regenerate from the secret when testing.');
    }

    /**
     * Move money in via a balanced posting, exactly as production would.
     */
    private function fund(int $userId, Wallet $system, Wallet $wallet, int $amount, Currency $currency): void
    {
        DB::transaction(function () use ($userId, $system, $wallet, $amount, $currency): void {
            $transaction = LedgerTransaction::create([
                'reference' => 'seed-funding:'.Str::uuid(),
                'type' => LedgerTransaction::TYPE_FUNDING,
                'status' => LedgerTransaction::STATUS_COMPLETED,
                'user_id' => $userId,
                'metadata' => ['source' => 'database seeder'],
            ]);

            LedgerEntry::create([
                'transaction_id' => $transaction->id,
                'wallet_id' => $system->id,
                'direction' => LedgerEntry::DIRECTION_DEBIT,
                'amount' => $amount,
                'currency' => $currency->value,
            ]);

            LedgerEntry::create([
                'transaction_id' => $transaction->id,
                'wallet_id' => $wallet->id,
                'direction' => LedgerEntry::DIRECTION_CREDIT,
                'amount' => $amount,
                'currency' => $currency->value,
            ]);
        });
    }
}
