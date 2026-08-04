<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Base32 TOTP seed. Stored encrypted at rest via the model cast,
            // so the column must hold ciphertext rather than the raw 32 chars.
            $table->text('totp_secret')->nullable()->after('password');
            $table->timestamp('totp_confirmed_at')->nullable()->after('totp_secret');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['totp_secret', 'totp_confirmed_at']);
        });
    }
};
