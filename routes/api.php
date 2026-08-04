<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LedgerController;
use App\Http\Controllers\Api\MockRateController;
use App\Http\Controllers\Api\SettlementWebhookController;
use App\Http\Controllers\Api\SwapController;
use App\Http\Controllers\Api\TwoFactorChallengeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tupay API
|--------------------------------------------------------------------------
|
| Three tiers of protection, deliberately distinct:
|
|   public          -> credential exchange only, aggressively rate limited
|   auth:sanctum    -> bearer token; sufficient for reads
|   + elevated:*    -> bearer token AND a single-use, action-bound EAT
|
*/

// Brute-force protection on the credential endpoint.
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1')
    ->name('auth.login');

// Stands in for the third-party rate provider.
Route::get('/mock/rates', MockRateController::class)->name('mock.rates');

/*
| Settlement webhooks authenticate by HMAC signature, not by bearer token —
| the caller is a machine, not a session. Placed outside the sanctum group
| for that reason.
*/
Route::post('/webhooks/settlement', SettlementWebhookController::class)
    ->middleware('webhook.signature')
    ->name('webhooks.settlement');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');

    // Step-up: spend a TOTP code, receive an EAT bound to one exact action.
    Route::post('/2fa/challenge', TwoFactorChallengeController::class)
        ->middleware('throttle:10,1')
        ->name('2fa.challenge');

    // Read-only: a bearer token alone is sufficient.
    Route::get('/ledger/{wallet_id}', LedgerController::class)->name('ledger.show');

    // Sensitive: bearer token PLUS a single-use elevated action token whose
    // hash must match these exact parameters.
    Route::post('/swap', SwapController::class)
        ->middleware('elevated:swap')
        ->name('swap.execute');
});
