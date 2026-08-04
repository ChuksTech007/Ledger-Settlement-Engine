<?php

declare(strict_types=1);

namespace App\Domain\Money;

enum Currency: string
{
    case NGN = 'NGN';
    case CNY = 'CNY';

    /**
     * Number of decimal places between the major unit and its subunit.
     * 1 NGN = 100 kobo, 1 CNY = 100 fen.
     */
    public function exponent(): int
    {
        return match ($this) {
            self::NGN, self::CNY => 2,
        };
    }

    /**
     * Subunits in one major unit, as a BCMath-safe integer string.
     *
     * @return numeric-string
     */
    public function subunitFactor(): string
    {
        return Decimal::guardInteger(bcpow('10', (string) $this->exponent(), 0), 'subunit factor');
    }

    public function subunitName(): string
    {
        return match ($this) {
            self::NGN => 'kobo',
            self::CNY => 'fen',
        };
    }
}
