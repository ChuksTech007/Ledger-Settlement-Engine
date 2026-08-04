<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\StepUp\ElevatedAction;
use App\Domain\StepUp\StepUpException;
use App\Models\User;
use App\Services\StepUp\ElevatedActionTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for endpoints that need step-up elevation.
 *
 * Applied as `elevated:swap`. Runs after auth:sanctum, so the bearer identity
 * is already established; this layer answers the separate question of whether
 * *this specific action with these specific parameters* was approved via TOTP.
 *
 * Consumption happens here, before the controller runs. That is intentional:
 * the spec requires the token to be invalidated on first read, so a request
 * that fails later validation still burns its token rather than leaving a
 * spent-but-live credential in play.
 */
final class RequireElevatedActionToken
{
    public const HEADER = 'X-Elevated-Action-Token';

    public function __construct(
        private readonly ElevatedActionTokenService $tokens,
    ) {}

    public function handle(Request $request, Closure $next, string $action): Response
    {
        $elevatedAction = ElevatedAction::tryFrom($action);

        if ($elevatedAction === null) {
            throw StepUpException::malformed();
        }

        $token = $request->header(self::HEADER);

        if (! is_string($token) || trim($token) === '') {
            throw StepUpException::missingToken();
        }

        /** @var User $user */
        $user = $request->user();

        $actionHash = $this->tokens->consume(
            trim($token),
            $user,
            $elevatedAction->value,
            $elevatedAction->parametersFromRequest($request),
        );

        // Downstream handlers can record which approval authorised the posting.
        $request->attributes->set('elevated_action_hash', $actionHash);

        return $next($request);
    }
}
