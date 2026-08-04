<?php

declare(strict_types=1);

namespace App\Domain\StepUp;

use Illuminate\Http\Request;

/**
 * The catalogue of actions that require step-up elevation, and — critically —
 * the single definition of which fields make up each action's canonical
 * parameter set.
 *
 * Both the challenge endpoint and the protected endpoint derive the hash
 * through this enum. If they built their parameter arrays independently, any
 * drift between the two would either break every legitimate request or, worse,
 * quietly widen what a token authorises. One definition, used twice.
 */
enum ElevatedAction: string
{
    case Swap = 'swap';

    /**
     * Extract the canonical parameters from a challenge's action_payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function parametersFromPayload(array $payload): array
    {
        return match ($this) {
            self::Swap => [
                'source_currency' => $payload['source_currency'] ?? null,
                'target_currency' => $payload['target_currency'] ?? null,
                'amount_subunits' => $payload['amount_subunits'] ?? null,
            ],
        };
    }

    /**
     * Extract the same canonical parameters from the real HTTP request.
     *
     * @return array<string, mixed>
     */
    public function parametersFromRequest(Request $request): array
    {
        return match ($this) {
            self::Swap => [
                'source_currency' => $request->input('source_currency'),
                'target_currency' => $request->input('target_currency'),
                'amount_subunits' => $request->input('amount_subunits'),
            ],
        };
    }

    /**
     * Validation rules applied to the action_payload at challenge time, so a
     * token can never be minted for parameters the swap endpoint would reject.
     *
     * @return array<string, mixed>
     */
    public function payloadRules(): array
    {
        return match ($this) {
            self::Swap => [
                'action_payload.source_currency' => ['required', 'string', 'in:NGN,CNY'],
                'action_payload.target_currency' => ['required', 'string', 'in:NGN,CNY', 'different:action_payload.source_currency'],
                'action_payload.amount_subunits' => ['required', 'integer', 'min:1'],
            ],
        };
    }
}
