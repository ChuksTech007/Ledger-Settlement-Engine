<?php

declare(strict_types=1);

namespace App\Domain\StepUp;

use JsonException;
use RuntimeException;

/**
 * Produces the SHA-256 fingerprint that an Elevated Action Token is bound to.
 *
 * The threat this closes: a user completes 2FA to authorise a ₦1,000 swap, and
 * the resulting token is then replayed against a ₦10,000,000 swap. Binding the
 * token to the *intent* rather than merely to the *user* means the token is
 * worthless for any action other than the one that was actually approved.
 *
 * Canonicalisation is the whole game here. If the challenge and the swap
 * serialise the same logical payload differently, the hashes diverge and every
 * legitimate request 422s; if canonicalisation is too lax, two materially
 * different payloads collide and the binding is meaningless. Rules:
 *
 *   - object keys sorted recursively, so key order carries no meaning
 *   - scalars normalised to strings, so 1000 and "1000" agree
 *   - the acting user id is folded in, so one user's EAT cannot authorise
 *     another user's action
 */
final class ActionHasher
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function hash(string $action, array $parameters, int $userId): string
    {
        $canonical = self::canonicalise([
            'action' => $action,
            'parameters' => $parameters,
            'user_id' => $userId,
        ]);

        try {
            $encoded = json_encode(
                $canonical,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $e) {
            throw new RuntimeException('Action payload could not be canonicalised.', 0, $e);
        }

        return hash('sha256', $encoded);
    }

    /**
     * Recursively sort keys and normalise scalars to strings.
     *
     * Booleans become "true"/"false" rather than "1"/"" so they cannot be
     * confused with the integers 1 and 0.
     */
    private static function canonicalise(mixed $value): mixed
    {
        if (is_array($value)) {
            $isList = array_is_list($value);

            $mapped = array_map(self::canonicalise(...), $value);

            if (! $isList) {
                ksort($mapped, SORT_STRING);
            }

            return $mapped;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return null;
        }

        if (is_float($value)) {
            // A float in a money payload is a bug upstream; refuse rather than
            // hash a value whose textual form is platform-dependent.
            throw new RuntimeException('Floats are not permitted in an action payload; use integer subunits.');
        }

        return (string) $value;
    }
}
