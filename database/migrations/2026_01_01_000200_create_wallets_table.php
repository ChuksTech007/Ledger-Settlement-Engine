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
        Schema::create('wallets', function (Blueprint $table): void {
            // UUID primary keys are a deliberate choice, not decoration: the swap
            // engine must acquire distributed locks on wallet ids "sorted in strict
            // alphabetical order", which is only meaningful for string identifiers.
            $table->uuid('id')->primary();

            // Nullable because system/liquidity wallets belong to the house, not a user.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            $table->char('currency', 3);

            // 'user'   -> balance may never go negative (enforced by DB trigger)
            // 'system' -> liquidity counterparty, expected to run negative
            $table->string('type', 16)->default('user');

            $table->timestamps();

            // A user holds exactly one wallet per currency.
            $table->unique(['user_id', 'currency']);
            $table->index(['type', 'currency']);
        });

        // NOTE: there is deliberately NO `balance` column on this table.
        // Balance is derived exclusively from ledger_entries.signed_amount.

        DB::statement("ALTER TABLE wallets ADD CONSTRAINT wallets_type_check CHECK (type IN ('user', 'system'))");
        DB::statement("ALTER TABLE wallets ADD CONSTRAINT wallets_currency_check CHECK (currency IN ('NGN', 'CNY'))");

        // Exactly one system wallet per currency.
        DB::statement("CREATE UNIQUE INDEX wallets_system_currency_unique ON wallets (currency) WHERE type = 'system'");
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
