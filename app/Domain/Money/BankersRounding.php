<?php

declare(strict_types=1);

namespace App\Domain\Money;

use InvalidArgumentException;

/**
 * Banker's rounding (ROUND_HALF_EVEN) over arbitrary-precision decimal strings.
 *
 * PHP's native round() goes through a float and therefore cannot be trusted for
 * money; PHP_ROUND_HALF_EVEN inherits the same binary representation problem.
 * BCMath has no rounding mode at all — bcdiv simply truncates. So the half-even
 * rule is implemented here explicitly, on strings, with no float anywhere in
 * the path.
 *
 * Why half-even rather than half-up: rounding .5 consistently upward imparts a
 * systematic bias that accumulates across millions of conversions and leaves the
 * ledger permanently long. Half-even distributes ties symmetrically.
 */
final class BankersRounding
{
    /**
     * Round a decimal string to an integer string using ROUND_HALF_EVEN.
     */
    public static function toInteger(string $value): string
    {
        if (! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException("Not a decimal string: {$value}");
        }

        $negative = str_starts_with($value, '-');
        $magnitude = $negative ? substr($value, 1) : $value;

        [$integerPart, $fractionPart] = array_pad(explode('.', $magnitude, 2), 2, '');

        // Strip trailing zeros so "1.50" and "1.5" take the same branch.
        $fractionPart = rtrim($fractionPart, '0');

        if ($fractionPart === '') {
            return self::applySign($integerPart, $negative);
        }

        $scale = strlen($fractionPart);
        $fraction = Decimal::guard('0.'.$fractionPart, 'fractional part');
        $whole = Decimal::guardInteger($integerPart === '' ? '0' : $integerPart, 'integer part');

        $comparison = bccomp($fraction, '0.5', $scale + 1);

        $roundUp = match (true) {
            $comparison > 0 => true,
            $comparison < 0 => false,
            // Exact tie: round toward the even neighbour.
            default => bcmod($whole, '2') !== '0',
        };

        if ($roundUp) {
            $integerPart = bcadd($whole, '1', 0);
        }

        return self::applySign($integerPart, $negative);
    }

    private static function applySign(string $integerPart, bool $negative): string
    {
        $integerPart = ltrim($integerPart, '0');

        if ($integerPart === '') {
            // Never emit "-0".
            return '0';
        }

        return $negative ? '-'.$integerPart : $integerPart;
    }
}
