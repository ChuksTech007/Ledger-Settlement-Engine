<?php

declare(strict_types=1);

namespace App\Domain\Money;

use InvalidArgumentException;
use JsonSerializable;

/**
 * An immutable amount of money held as 64-bit integer subunits.
 *
 * The entire domain speaks this type. Floats and DECIMAL never appear in the
 * arithmetic path: a value either is an integer number of kobo/fen, or it is
 * not money. Conversions that produce fractional subunits are resolved with
 * banker's rounding at the single point where the fraction is created.
 */
final class Money implements JsonSerializable
{
    /** Largest value a PostgreSQL BIGINT can hold. */
    private const MAX_SUBUNITS = '9223372036854775807';

    private const MIN_SUBUNITS = '-9223372036854775808';

    private function __construct(
        public readonly int $subunits,
        public readonly Currency $currency,
    ) {}

    public static function ofSubunits(int $subunits, Currency $currency): self
    {
        return new self($subunits, $currency);
    }

    /**
     * Build from a major-unit decimal string, e.g. "1500.75" NGN -> 150075 kobo.
     * Accepts strings only; passing a float here would defeat the point.
     */
    public static function ofMajorUnits(string $amount, Currency $currency): self
    {
        if (preg_match('/^-?\d+(\.\d+)?$/', $amount) !== 1) {
            throw new InvalidArgumentException("Not a decimal amount: {$amount}");
        }

        $scaled = bcmul(
            Decimal::guard($amount, 'major units'),
            $currency->subunitFactor(),
            $currency->exponent() + 8,
        );

        return self::fromNumericString(BankersRounding::toInteger($scaled), $currency);
    }

    /**
     * Guarded conversion from a BCMath integer string, rejecting anything that
     * would silently wrap when it hits a BIGINT column.
     */
    public static function fromNumericString(string $subunits, Currency $currency): self
    {
        $guarded = Decimal::guardInteger($subunits, 'subunits');

        // Range-checked against BIGINT before the int cast. Without this a
        // value beyond 2^63-1 would wrap to a negative number and post a credit
        // as a debit.
        if (bccomp($guarded, self::MAX_SUBUNITS, 0) > 0 || bccomp($guarded, self::MIN_SUBUNITS, 0) < 0) {
            throw new InvalidArgumentException("Amount overflows a 64-bit integer: {$subunits}");
        }

        return new self((int) $guarded, $currency);
    }

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return self::fromNumericString(
            bcadd($this->asString(), $other->asString(), 0),
            $this->currency,
        );
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return self::fromNumericString(
            bcsub($this->asString(), $other->asString(), 0),
            $this->currency,
        );
    }

    /**
     * Multiply by an arbitrary-precision decimal string (a rate, a spread
     * factor) and round the fractional subunits half-even.
     */
    public function multipliedBy(string $multiplier, int $scale = 18): self
    {
        $product = bcmul($this->asString(), Decimal::guard($multiplier, 'multiplier'), $scale);

        return self::fromNumericString(BankersRounding::toInteger($product), $this->currency);
    }

    /**
     * Apply a rate to produce an amount in a different currency.
     *
     * NGN and CNY both use an exponent of 2, so in practice no rescaling is
     * needed. The branch below is kept because a currency with a different
     * exponent would otherwise be silently mis-scaled by a factor of 10^n.
     */
    public function convertTo(Currency $target, string $rate, int $scale = 18): Money
    {
        $product = bcmul($this->asString(), Decimal::guard($rate, 'exchange rate'), $scale);

        if ($this->currency->exponent() !== $target->exponent()) {
            $factor = bcdiv(
                $target->subunitFactor(),
                $this->currency->subunitFactor(),
                $scale,
            );
            $product = bcmul(Decimal::guard($product, 'product'), Decimal::guard($factor, 'scale factor'), $scale);
        }

        return self::fromNumericString(BankersRounding::toInteger($product), $target);
    }

    /**
     * Basis-point share of this amount (100 bps = 1%), rounded half-even.
     */
    public function basisPoints(int $bps, int $scale = 18): self
    {
        $factor = bcdiv((string) $bps, '10000', $scale);

        return $this->multipliedBy($factor, $scale);
    }

    public function isNegative(): bool
    {
        return $this->subunits < 0;
    }

    public function isZero(): bool
    {
        return $this->subunits === 0;
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->subunits > $other->subunits;
    }

    public function isLessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->subunits < $other->subunits;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->subunits === $other->subunits;
    }

    /**
     * Human-facing representation only. Never feed this back into arithmetic.
     */
    public function toMajorUnits(): string
    {
        return bcdiv((string) $this->subunits, $this->currency->subunitFactor(), $this->currency->exponent());
    }

    /**
     * @return array{subunits: int, currency: string, formatted: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'subunits' => $this->subunits,
            'currency' => $this->currency->value,
            'formatted' => $this->toMajorUnits(),
        ];
    }

    /**
     * The subunit count as a BCMath-safe string.
     *
     * @return numeric-string
     */
    private function asString(): string
    {
        return Decimal::guardInteger((string) $this->subunits, 'subunits');
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Currency mismatch: {$this->currency->value} vs {$other->currency->value}",
            );
        }
    }
}
