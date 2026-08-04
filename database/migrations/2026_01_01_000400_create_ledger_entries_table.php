<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->uuid('transaction_id');
            $table->uuid('wallet_id');

            $table->string('direction', 6);

            // Subunits only (kobo / fen). 64-bit integer, never a float or decimal.
            // Always stored as a positive magnitude; the sign lives in `direction`.
            $table->bigInteger('amount');

            $table->char('currency', 3);

            // Generated column: the single source of truth for balance arithmetic.
            // Keeping the sign in the database (rather than in PHP) means the
            // non-negative trigger and any ad-hoc SQL agree by construction.
            $table->bigInteger('signed_amount')
                ->storedAs("CASE WHEN direction = 'credit' THEN amount ELSE -amount END");

            $table->timestamps();

            $table->foreign('transaction_id')->references('id')->on('ledger_transactions')->cascadeOnDelete();
            $table->foreign('wallet_id')->references('id')->on('wallets')->restrictOnDelete();

            // Primary read path: GET /api/ledger/{wallet_id} paginated newest-first.
            // Composite (wallet_id, id DESC) serves both keyset pagination and the
            // balance SUM as an index-only scan.
            $table->index(['wallet_id', 'id'], 'ledger_entries_wallet_id_id_index');
            $table->index('transaction_id');
        });

        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_direction_check CHECK (direction IN ('debit', 'credit'))");

        // A zero or negative magnitude would let a 'debit' silently act as a credit.
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_amount_positive CHECK (amount > 0)');

        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_currency_check CHECK (currency IN ('NGN', 'CNY'))");

        // Ledger rows are immutable: an error is corrected with a reversing entry,
        // never by editing history.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledger_entries_forbid_mutation()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'ledger_entries are append-only; post a reversing entry instead'
                    USING ERRCODE = '23514';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER ledger_entries_no_update_delete
            BEFORE UPDATE OR DELETE ON ledger_entries
            FOR EACH ROW EXECUTE FUNCTION ledger_entries_forbid_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS ledger_entries_no_update_delete ON ledger_entries');
        DB::statement('DROP FUNCTION IF EXISTS ledger_entries_forbid_mutation()');
        Schema::dropIfExists('ledger_entries');
    }
};
