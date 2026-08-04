<?php

declare(strict_types=1);

use App\Domain\Money\Currency;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The database-layer guarantees.
 *
 * Every assertion here deliberately BYPASSES the application. The swap service
 * already refuses to overdraw a wallet, so testing through it would only prove
 * the PHP check works. What matters is that the invariants still hold against
 * raw SQL — a migration, a console command, or a future bug that skips the
 * service entirely must not be able to corrupt the ledger.
 */
beforeEach(function (): void {
    $this->system = seedSystemWallets();
    $this->user = User::factory()->withWallets()->create();
    $this->ngn = $this->user->walletFor(Currency::NGN);
    $this->cny = $this->user->walletFor(Currency::CNY);
});

/**
 * Insert a ledger entry through the query builder, with no model events and
 * no application validation whatsoever.
 */
function rawEntry(string $transactionId, string $walletId, string $direction, int $amount, string $currency): void
{
    DB::table('ledger_entries')->insert([
        'transaction_id' => $transactionId,
        'wallet_id' => $walletId,
        'direction' => $direction,
        'amount' => $amount,
        'currency' => $currency,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Run something that is expected to violate a constraint, and return the
 * database's complaint.
 *
 * The wrapping transaction is essential rather than cosmetic: PostgreSQL
 * aborts the WHOLE transaction on a constraint violation, and the suite is
 * already inside one courtesy of RefreshDatabase. Without a SAVEPOINT to roll
 * back to, the expected failure would poison the connection and every
 * subsequent assertion in the test would die with "current transaction is
 * aborted" instead of checking what it was written to check.
 */
function failingQuery(Closure $callback): string
{
    try {
        DB::transaction($callback, attempts: 1);
    } catch (QueryException $e) {
        return $e->getMessage();
    }

    throw new RuntimeException('Expected this query to be rejected, but it succeeded.');
}

function rawTransaction(?int $userId = null, string $type = 'funding'): string
{
    $id = (string) Str::uuid();

    DB::table('ledger_transactions')->insert([
        'id' => $id,
        'reference' => 'raw:'.Str::uuid(),
        'type' => $type,
        'status' => 'completed',
        'user_id' => $userId,
        'metadata' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

describe('schema shape', function (): void {
    it('has no balance column on wallets', function (): void {
        // The spec forbids a mutable balance column outright.
        $columns = DB::getSchemaBuilder()->getColumnListing('wallets');

        expect($columns)->not->toContain('balance');
        expect($columns)->not->toContain('balance_subunits');
    });

    it('stores amounts as 64-bit integers', function (): void {
        $type = DB::selectOne(
            "SELECT data_type FROM information_schema.columns
             WHERE table_name = 'ledger_entries' AND column_name = 'amount'"
        );

        expect($type->data_type)->toBe('bigint');
    });

    it('derives signed_amount as a stored generated column', function (): void {
        $column = DB::selectOne(
            "SELECT is_generated, generation_expression FROM information_schema.columns
             WHERE table_name = 'ledger_entries' AND column_name = 'signed_amount'"
        );

        expect($column->is_generated)->toBe('ALWAYS');
        expect($column->generation_expression)->toContain('credit');
    });

    it('signs credits positive and debits negative', function (): void {
        $tx = rawTransaction($this->user->id);
        rawEntry($tx, $this->system['NGN']->id, 'debit', 5_000, 'NGN');
        rawEntry($tx, $this->ngn->id, 'credit', 5_000, 'NGN');

        $rows = DB::table('ledger_entries')->where('transaction_id', $tx)->get();

        foreach ($rows as $row) {
            $expected = $row->direction === 'credit' ? $row->amount : -$row->amount;
            expect((int) $row->signed_amount)->toBe((int) $expected);
        }
    });
});

describe('non-negative balance trigger', function (): void {
    it('blocks an overdraft written by raw SQL', function (): void {
        fundWallet($this->user, Currency::NGN, 10_000);

        $tx = rawTransaction($this->user->id, 'swap');

        // One subunit more than exists. The application is not involved.
        $threw = null;

        try {
            DB::transaction(function () use ($tx): void {
                rawEntry($tx, $this->ngn->id, 'debit', 10_001, 'NGN');
                rawEntry($tx, $this->system['NGN']->id, 'credit', 10_001, 'NGN');
            });
        } catch (QueryException $e) {
            $threw = $e->getMessage();
        }

        expect($threw)->toContain('insufficient_funds');

        // And the balance is provably untouched.
        expect($this->ngn->fresh()->balance()->subunits)->toBe(10_000);
    });

    it('permits a withdrawal down to exactly zero', function (): void {
        fundWallet($this->user, Currency::NGN, 10_000);

        $tx = rawTransaction($this->user->id, 'swap');

        DB::transaction(function () use ($tx): void {
            rawEntry($tx, $this->ngn->id, 'debit', 10_000, 'NGN');
            rawEntry($tx, $this->system['NGN']->id, 'credit', 10_000, 'NGN');
        });

        expect($this->ngn->fresh()->balance()->subunits)->toBe(0);
    });

    it('allows a system wallet to carry a negative position', function (): void {
        // The house is the counterparty to every user credit, so it must be
        // exempt — otherwise double entry could never balance.
        fundWallet($this->user, Currency::NGN, 50_000);

        expect($this->system['NGN']->fresh()->balance()->subunits)->toBe(-50_000);
    });
});

describe('balanced posting trigger', function (): void {
    /*
     * This trigger is DEFERRABLE INITIALLY DEFERRED, so PostgreSQL evaluates it
     * at COMMIT — that is precisely what allows a multi-leg posting to be
     * written one row at a time without the first INSERT being rejected.
     *
     * Under RefreshDatabase the suite holds an outer transaction and ours is a
     * SAVEPOINT, so no COMMIT ever happens and the deferred check would never
     * fire. `SET CONSTRAINTS ALL IMMEDIATE` forces any pending deferred checks
     * to run at that point, which is how the behaviour is surfaced here.
     */
    it('rejects a transaction whose legs do not net to zero', function (): void {
        $tx = rawTransaction($this->user->id);

        $message = failingQuery(function () use ($tx): void {
            rawEntry($tx, $this->system['NGN']->id, 'debit', 1_000, 'NGN');
            rawEntry($tx, $this->ngn->id, 'credit', 999, 'NGN');

            // Stand in for COMMIT.
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });

        expect($message)->toContain('unbalanced_transaction');
    });

    it('rejects a single unpaired entry', function (): void {
        $tx = rawTransaction($this->user->id);

        $message = failingQuery(function () use ($tx): void {
            rawEntry($tx, $this->ngn->id, 'credit', 1_000, 'NGN');

            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });

        expect($message)->toContain('unbalanced_transaction');
    });

    it('accepts a balanced pair when the deferred check runs', function (): void {
        // The other side of the coin: with the check deferred, writing the
        // legs one at a time is legal, and forcing the check afterwards passes.
        $tx = rawTransaction($this->user->id);

        DB::transaction(function () use ($tx): void {
            rawEntry($tx, $this->system['NGN']->id, 'debit', 1_000, 'NGN');
            rawEntry($tx, $this->ngn->id, 'credit', 1_000, 'NGN');

            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });

        expect($this->ngn->fresh()->balance()->subunits)->toBe(1_000);
    });

    it('balances each currency independently in a cross-currency swap', function (): void {
        fundWallet($this->user, Currency::NGN, 100_000);

        $tx = rawTransaction($this->user->id, 'swap');

        // NGN nets to zero and CNY nets to zero — summing them together would
        // be meaningless, so the trigger groups by currency.
        DB::transaction(function () use ($tx): void {
            rawEntry($tx, $this->ngn->id, 'debit', 100_000, 'NGN');
            rawEntry($tx, $this->system['NGN']->id, 'credit', 100_000, 'NGN');
            rawEntry($tx, $this->system['CNY']->id, 'debit', 500, 'CNY');
            rawEntry($tx, $this->cny->id, 'credit', 500, 'CNY');
        });

        expect(ledgerNetByCurrency())->toEqual(['NGN' => 0, 'CNY' => 0]);
    });

    it('refuses a posting that balances only when currencies are conflated', function (): void {
        $tx = rawTransaction($this->user->id, 'swap');

        // Sums to zero if you ignore currency, but neither NGN nor CNY balances
        // on its own — which is why the trigger groups by currency.
        $message = failingQuery(function () use ($tx): void {
            rawEntry($tx, $this->system['NGN']->id, 'debit', 1_000, 'NGN');
            rawEntry($tx, $this->cny->id, 'credit', 1_000, 'CNY');

            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });

        expect($message)->toContain('unbalanced_transaction');
    });
});

describe('append-only enforcement', function (): void {
    beforeEach(function (): void {
        fundWallet($this->user, Currency::NGN, 25_000);
        $this->entryId = DB::table('ledger_entries')->where('wallet_id', $this->ngn->id)->value('id');
    });

    it('rejects an UPDATE to a ledger entry', function (): void {
        $message = failingQuery(fn () => DB::table('ledger_entries')
            ->where('id', $this->entryId)
            ->update(['amount' => 1]));

        expect($message)->toContain('append-only');
        expect($this->ngn->fresh()->balance()->subunits)->toBe(25_000);
    });

    it('rejects a DELETE of a ledger entry', function (): void {
        $message = failingQuery(fn () => DB::table('ledger_entries')
            ->where('id', $this->entryId)
            ->delete());

        expect($message)->toContain('append-only');
        expect($this->ngn->fresh()->balance()->subunits)->toBe(25_000);
    });

    it('corrects an error with a reversing entry instead', function (): void {
        // The sanctioned route: history is added to, never rewritten.
        $tx = rawTransaction($this->user->id);

        DB::transaction(function () use ($tx): void {
            rawEntry($tx, $this->ngn->id, 'debit', 25_000, 'NGN');
            rawEntry($tx, $this->system['NGN']->id, 'credit', 25_000, 'NGN');
        });

        expect($this->ngn->fresh()->balance()->subunits)->toBe(0);
        expect(LedgerEntry::query()->where('wallet_id', $this->ngn->id)->count())->toBe(2);
    });
});

describe('column constraints', function (): void {
    it('rejects a zero amount', function (): void {
        $tx = rawTransaction($this->user->id);

        expect(failingQuery(fn () => rawEntry($tx, $this->ngn->id, 'credit', 0, 'NGN')))
            ->toContain('amount_positive');
    });

    it('rejects a negative amount', function (): void {
        // The sign belongs in `direction`; a negative magnitude would let a
        // debit act as a credit.
        $tx = rawTransaction($this->user->id);

        expect(failingQuery(fn () => rawEntry($tx, $this->ngn->id, 'debit', -500, 'NGN')))
            ->toContain('amount_positive');
    });

    it('rejects an unknown direction', function (): void {
        $tx = rawTransaction($this->user->id);

        // Exactly six characters, so it fits the column and is rejected by the
        // CHECK constraint rather than by string truncation.
        expect(failingQuery(fn () => rawEntry($tx, $this->ngn->id, 'refund', 500, 'NGN')))
            ->toContain('direction_check');
    });

    it('rejects an unsupported currency', function (): void {
        $tx = rawTransaction($this->user->id);

        expect(failingQuery(fn () => rawEntry($tx, $this->ngn->id, 'credit', 500, 'USD')))
            ->toContain('currency_check');
    });

    it('allows only one system wallet per currency', function (): void {
        expect(failingQuery(fn () => Wallet::query()->create([
            'user_id' => null,
            'currency' => 'NGN',
            'type' => Wallet::TYPE_SYSTEM,
        ])))->toContain('wallets_system_currency_unique');
    });

    it('allows only one wallet per user per currency', function (): void {
        expect(failingQuery(fn () => Wallet::query()->create([
            'user_id' => $this->user->id,
            'currency' => 'NGN',
            'type' => Wallet::TYPE_USER,
        ])))->toContain('wallets_user_id_currency_unique');
    });

    it('enforces a unique transaction reference', function (): void {
        $reference = 'duplicate-reference';

        LedgerTransaction::query()->create([
            'reference' => $reference,
            'type' => 'funding',
            'status' => 'completed',
            'user_id' => $this->user->id,
            'metadata' => [],
        ]);

        expect(failingQuery(fn () => LedgerTransaction::query()->create([
            'reference' => $reference,
            'type' => 'funding',
            'status' => 'completed',
            'user_id' => $this->user->id,
            'metadata' => [],
        ])))->toContain('ledger_transactions_reference_unique');
    });
});

describe('derived balance', function (): void {
    it('agrees with the wallet_balances view', function (): void {
        fundWallet($this->user, Currency::NGN, 77_777);

        $viewBalance = (int) DB::table('wallet_balances')
            ->where('wallet_id', $this->ngn->id)
            ->value('balance_subunits');

        expect($viewBalance)->toBe(77_777);
        expect($this->ngn->fresh()->balance()->subunits)->toBe(77_777);
    });

    it('reports zero for a wallet with no entries', function (): void {
        expect($this->cny->balance()->subunits)->toBe(0);
    });
});
