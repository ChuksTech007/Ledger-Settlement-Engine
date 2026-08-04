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
        Schema::create('settlement_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // The idempotency key. A UNIQUE index is the authoritative guard:
            // Redis is a fast path, but only the database can make "process this
            // provider_reference exactly once" survive a cache flush.
            $table->string('provider_reference')->unique();

            $table->uuid('transaction_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Terminal state reached so far. Out-of-order delivery means a
            // COMPLETED can land before INITIATED; the state machine compares
            // rank rather than assuming arrival order.
            $table->string('status', 32);
            $table->unsignedTinyInteger('status_rank');

            $table->char('currency', 3)->nullable();
            $table->bigInteger('amount')->nullable();

            $table->jsonb('payload')->default(DB::raw("'{}'::jsonb"));
            $table->jsonb('seen_statuses')->default(DB::raw("'[]'::jsonb"));

            $table->timestamp('ledger_posted_at')->nullable();
            $table->timestamps();

            $table->foreign('transaction_id')->references('id')->on('ledger_transactions')->nullOnDelete();
            $table->index(['status', 'created_at']);
        });

        DB::statement("ALTER TABLE settlement_events ADD CONSTRAINT settlement_events_status_check CHECK (status IN ('INITIATED', 'PROCESSING', 'COMPLETED', 'FAILED'))");
        DB::statement('ALTER TABLE settlement_events ADD CONSTRAINT settlement_events_amount_positive CHECK (amount IS NULL OR amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_events');
    }
};
