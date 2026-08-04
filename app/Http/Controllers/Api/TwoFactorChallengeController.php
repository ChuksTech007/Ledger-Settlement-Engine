<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\StepUp\ElevatedAction;
use App\Domain\StepUp\StepUpException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireElevatedActionToken;
use App\Models\User;
use App\Services\StepUp\ElevatedActionTokenService;
use App\Services\StepUp\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/2fa/challenge
 *
 * Trades a valid TOTP code plus a declared action_payload for an Elevated
 * Action Token. The raw TOTP code never travels with the swap itself; it is
 * spent here, once, and what continues onward is a token that authorises
 * nothing except the precise action described in the payload.
 */
final class TwoFactorChallengeController extends Controller
{
    public function __construct(
        private readonly TotpService $totp,
        private readonly ElevatedActionTokenService $tokens,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'totp_code' => ['required', 'string', 'regex:/^\d{6}$/'],
            'action' => ['required', 'string', 'in:'.implode(',', array_column(ElevatedAction::cases(), 'value'))],
            'action_payload' => ['required', 'array'],
        ]);

        $action = ElevatedAction::from($validated['action']);

        // Validate the payload against the action's own rules, so a token can
        // never be minted for parameters the target endpoint would reject.
        $request->validate($action->payloadRules());

        /** @var User $user */
        $user = $request->user();

        // Throws 422 on an invalid or already-spent code.
        $this->totp->verify($user, $validated['totp_code']);

        /** @var array<string, mixed> $payload */
        $payload = $request->input('action_payload', []);

        $issued = $this->tokens->issue(
            $user,
            $action->value,
            $action->parametersFromPayload($payload),
        );

        return response()->json([
            'elevated_action_token' => $issued['token'],
            'action' => $action->value,
            'action_hash' => $issued['action_hash'],
            'expires_in' => $issued['expires_in'],
            'expires_at' => $issued['expires_at'],
            'usage' => sprintf(
                'Send as the %s header. Single use, expires in %d seconds.',
                RequireElevatedActionToken::HEADER,
                $issued['expires_in'],
            ),
        ], 201);
    }

    /**
     * Surfaces StepUpException with the right status code.
     */
    public static function renderStepUpException(StepUpException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'error' => $e->errorCode,
        ], $e->status);
    }
}
