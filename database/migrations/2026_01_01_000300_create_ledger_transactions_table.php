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
        Schema::create('ledger_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Business-level idempotency handle (swap request id, provider reference...).
            $table->string('reference')->unique();

            $table->string('type', 32);
            $table->string('status', 32)->default('pending');

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Rate + slippage snapshot, provider payloads, etc. Audit trail for how
            // the subunit figures were derived, since the math must be reproducible.
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['type', 'status']);
        });

        DB::statement("ALTER TABLE ledger_transactions ADD CONSTRAINT ledger_transactions_type_check CHECK (type IN ('swap', 'settlement', 'funding'))");
        DB::statement("ALTER TABLE ledger_transactions ADD CONSTRAINT ledger_transactions_status_check CHECK (status IN ('pending', 'completed', 'failed', 'reversed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_transactions');
    }
};
