<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

final class AuthController extends Controller
{
    /**
     * A real bcrypt digest of a value no account will ever hold.
     *
     * Verifying against this when the email is unknown makes the failure path
     * cost the same as a genuine password check, so response timing cannot be
     * used to enumerate registered addresses. It must be a *valid* digest —
     * a malformed one makes Hash::check throw rather than return false.
     */
    private const TIMING_SAFE_DUMMY_HASH = '$2y$12$1FoxkWolBJqHdyb9j1kYNex5OSwugsLPFJDLAQOeVJA9ooIKOthWO';

    /**
     * Exchange credentials for a Sanctum bearer token.
     *
     * This token alone is enough for read-only endpoints. It is deliberately
     * NOT enough for /api/swap, which additionally demands a per-action
     * elevated token minted from a TOTP challenge.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        // Compare against a hash even when the user is missing, so response
        // timing does not reveal which addresses are registered.
        $passwordMatches = $user !== null
            ? Hash::check($credentials['password'], $user->password)
            : Hash::check($credentials['password'], self::TIMING_SAFE_DUMMY_HASH);

        if ($user === null || ! $passwordMatches) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $token = $user->createToken(
            $credentials['device_name'] ?? 'api-token',
            ['*'],
            now()->addDay(),
        );

        // Wallet identifiers are returned here because GET /api/ledger/{wallet_id}
        // needs one, and nothing else in the API exposes them — without this a
        // client would have no way to discover its own wallets. Eager loaded so
        // the response costs one extra query rather than one per currency.
        $user->load('wallets');

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'totp_enrolled' => $user->hasTotpEnabled(),
            ],
            'wallets' => $user->wallets->map(fn (Wallet $wallet): array => [
                'id' => $wallet->id,
                'currency' => $wallet->currency,
                // Derived from the ledger, never stored.
                'balance_subunits' => $wallet->balance()->subunits,
            ])->values(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['message' => 'Token revoked.']);
    }
}
