<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The two ledger invariants the spec demands, enforced at the storage layer so
 * they hold even against raw SQL that bypasses the application entirely.
 *
 *   1. No *user* wallet's calculated sub-total may drop below zero.
 *   2. Every transaction's debits and credits must net to exactly zero.
 *
 * A plain CHECK constraint cannot express either one: CHECK sees a single row,
 * while both invariants are aggregates over sibling rows. Triggers are the
 * sanctioned escape hatch, and the spec allows exactly this.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- Invariant 1: user wallets may never go negative -----------------
        //
        // AFTER INSERT (not BEFORE): the incoming row must be visible to the SUM,
        // otherwise the very entry that overdraws the wallet is excluded from the
        // check that is supposed to catch it.
        //
        // The swap engine takes SELECT ... FOR UPDATE on the wallet row before
        // posting, so concurrent transactions serialise here rather than racing.
        // This trigger is the backstop for anything that does not.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_non_negative_wallet_balance()
            RETURNS trigger AS $$
            DECLARE
                wallet_type TEXT;
                calculated_balance BIGINT;
            BEGIN
                SELECT type INTO wallet_type FROM wallets WHERE id = NEW.wallet_id;

                -- System/liquidity wallets are the counterparty to every user
                -- movement and are expected to carry a negative position.
                IF wallet_type IS DISTINCT FROM 'user' THEN
                    RETURN NEW;
                END IF;

                SELECT COALESCE(SUM(signed_amount), 0)
                INTO calculated_balance
                FROM ledger_entries
                WHERE wallet_id = NEW.wallet_id;

                IF calculated_balance < 0 THEN
                    RAISE EXCEPTION
                        'insufficient_funds: wallet % would settle at % subunits',
                        NEW.wallet_id, calculated_balance
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER ledger_entries_non_negative_balance
            AFTER INSERT ON ledger_entries
            DEFERRABLE INITIALLY IMMEDIATE
            FOR EACH ROW EXECUTE FUNCTION enforce_non_negative_wallet_balance();
        SQL);

        // --- Invariant 2: debits and credits net to zero ---------------------
        //
        // DEFERRED, because a balanced posting is only balanced once every leg
        // has been written. Checking eagerly would reject the first INSERT of
        // every legitimate pair. Postgres re-runs this at COMMIT.
        //
        // Netting is per (transaction, currency): a cross-currency swap moves
        // NGN on one side and CNY on the other, and those must balance
        // independently rather than being summed into a meaningless total.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_balanced_transaction()
            RETURNS trigger AS $$
            DECLARE
                unbalanced RECORD;
            BEGIN
                SELECT currency, SUM(signed_amount) AS net
                INTO unbalanced
                FROM ledger_entries
                WHERE transaction_id = NEW.transaction_id
                GROUP BY currency
                HAVING SUM(signed_amount) <> 0
                LIMIT 1;

                IF FOUND THEN
                    RAISE EXCEPTION
                        'unbalanced_transaction: % legs net to % subunits in %',
                        NEW.transaction_id, unbalanced.net, unbalanced.currency
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER ledger_entries_balanced_transaction
            AFTER INSERT ON ledger_entries
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION enforce_balanced_transaction();
        SQL);

        // --- Derived balances -------------------------------------------------
        //
        // The read model that replaces the forbidden `wallets.balance` column.
        // Backed by the (wallet_id, id) index, so this is an index-only scan.
        DB::statement(<<<'SQL'
            CREATE VIEW wallet_balances AS
            SELECT
                w.id AS wallet_id,
                w.user_id,
                w.currency,
                w.type,
                COALESCE(SUM(e.signed_amount), 0)::BIGINT AS balance_subunits
            FROM wallets w
            LEFT JOIN ledger_entries e ON e.wallet_id = w.id
            GROUP BY w.id, w.user_id, w.currency, w.type;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS wallet_balances');
        DB::statement('DROP TRIGGER IF EXISTS ledger_entries_balanced_transaction ON ledger_entries');
        DB::statement('DROP TRIGGER IF EXISTS ledger_entries_non_negative_balance ON ledger_entries');
        DB::statement('DROP FUNCTION IF EXISTS enforce_balanced_transaction()');
        DB::statement('DROP FUNCTION IF EXISTS enforce_non_negative_wallet_balance()');
    }
};
