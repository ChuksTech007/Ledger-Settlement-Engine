<?php

declare(strict_types=1);

namespace App\Domain\Money;

use InvalidArgumentException;

/**
 * Boundary guard for arbitrary-precision decimal strings.
 *
 * Every BCMath function requires a numeric string, and feeding it anything else
 * is undefined behaviour that silently yields "0" rather than raising. In a
 * ledger that is the worst possible failure mode: a malformed rate would not
 * crash, it would quietly post a zero-value movement.
 *
 * Validating through is_numeric() (rather than a regex) is deliberate — it is
 * the check static analysis can actually narrow, so the guarantee is enforced
 * at compile time as well as at run time.
 */
final class Decimal
{
    /**
     * @return numeric-string
     */
    public static function guard(string $value, string $context = 'value'): string
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException("Not a numeric string for {$context}: {$value}");
        }

        return $value;
    }

    /**
     * Guard a value that must additionally be a plain integer string —
     * no exponent, no fractional part. Used wherever a figure is about to
     * become a BIGINT subunit count.
     *
     * @return numeric-string
     */
    public static function guardInteger(string $value, string $context = 'value'): string
    {
        if (preg_match('/^-?\d+$/', $value) !== 1) {
            throw new InvalidArgumentException("Not an integer string for {$context}: {$value}");
        }

        return self::guard($value, $context);
    }
}
