<?php

declare(strict_types=1);

use App\Domain\Money\BankersRounding;
use App\Domain\Money\Currency;
use App\Domain\Money\Decimal;
use App\Domain\Money\Money;

/**
 * Financial precision.
 *
 * These are pure functions with no I/O, and they are the single most delicate
 * part of the system: every subunit that reaches the ledger passes through
 * them. A silent error here would not crash anything — it would just quietly
 * make the books wrong.
 */
describe("banker's rounding", function (): void {
    it('rounds ties to the even neighbour', function (string $input, string $expected): void {
        expect(BankersRounding::toInteger($input))->toBe($expected);
    })->with([
        // The defining property: .5 does NOT always go up.
        ['0.5', '0'],
        ['1.5', '2'],
        ['2.5', '2'],
        ['3.5', '4'],
        ['4.5', '4'],
        ['5.5', '6'],

        // Adjacent ties must diverge — this is what removes the bias.
        ['123.5', '124'],
        ['124.5', '124'],
    ]);

    it('is symmetric on negative values', function (string $input, string $expected): void {
        expect(BankersRounding::toInteger($input))->toBe($expected);
    })->with([
        ['-0.5', '0'],
        ['-1.5', '-2'],
        ['-2.5', '-2'],
        ['-3.5', '-4'],
    ]);

    it('rounds non-ties normally', function (string $input, string $expected): void {
        expect(BankersRounding::toInteger($input))->toBe($expected);
    })->with([
        ['2.4', '2'],
        ['2.6', '3'],
        ['0.4999999999', '0'],
        ['0.5000000001', '1'],
        ['-2.6', '-3'],
    ]);

    it('handles integers and trailing zeros', function (string $input, string $expected): void {
        expect(BankersRounding::toInteger($input))->toBe($expected);
    })->with([
        ['10', '10'],
        ['2.50000', '2'],
        ['3.50000', '4'],
        ['0', '0'],
        ['-0', '0'],
    ]);

    it('never emits negative zero', function (): void {
        expect(BankersRounding::toInteger('-0.4'))->toBe('0');
        expect(BankersRounding::toInteger('-0.5'))->toBe('0');
    });

    it('rejects a non-decimal string', function (): void {
        expect(fn () => BankersRounding::toInteger('not-a-number'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('preserves precision far beyond a float', function (): void {
        // 0.1 + 0.2 !== 0.3 in binary floating point. String maths has no
        // such problem, which is the entire reason this class exists.
        $sum = bcadd('0.1', '0.2', 20);

        expect($sum)->toBe('0.30000000000000000000');
        expect(BankersRounding::toInteger(bcmul($sum, '10', 20)))->toBe('3');
    });
});

describe('Money', function (): void {
    it('stores subunits as integers', function (): void {
        $money = Money::ofSubunits(150_075, Currency::NGN);

        expect($money->subunits)->toBe(150_075);
        expect($money->currency)->toBe(Currency::NGN);
        expect($money->toMajorUnits())->toBe('1500.75');
    });

    it('converts major units to subunits without a float', function (): void {
        expect(Money::ofMajorUnits('1500.75', Currency::NGN)->subunits)->toBe(150_075);
        expect(Money::ofMajorUnits('0.01', Currency::NGN)->subunits)->toBe(1);
        expect(Money::ofMajorUnits('0.005', Currency::NGN)->subunits)->toBe(0); // ties to even
        expect(Money::ofMajorUnits('0.015', Currency::NGN)->subunits)->toBe(2); // ties to even
    });

    it('refuses a float-shaped input', function (): void {
        expect(fn () => Money::ofMajorUnits('1.2e3', Currency::NGN))
            ->toThrow(InvalidArgumentException::class);
    });

    it('rejects values that would overflow a BIGINT', function (): void {
        // One past PostgreSQL's BIGINT ceiling. Without this guard the int
        // cast would wrap negative and post a credit as a debit.
        expect(fn () => Money::fromNumericString('9223372036854775808', Currency::NGN))
            ->toThrow(InvalidArgumentException::class);

        expect(Money::fromNumericString('9223372036854775807', Currency::NGN)->subunits)
            ->toBe(9223372036854775807);
    });

    it('adds and subtracts exactly', function (): void {
        $a = Money::ofSubunits(100_000, Currency::NGN);
        $b = Money::ofSubunits(33_333, Currency::NGN);

        expect($a->plus($b)->subunits)->toBe(133_333);
        expect($a->minus($b)->subunits)->toBe(66_667);
    });

    it('refuses to mix currencies', function (): void {
        $ngn = Money::ofSubunits(100, Currency::NGN);
        $cny = Money::ofSubunits(100, Currency::CNY);

        expect(fn () => $ngn->plus($cny))->toThrow(InvalidArgumentException::class);
        expect(fn () => $ngn->isGreaterThan($cny))->toThrow(InvalidArgumentException::class);
    });

    it('applies basis points with half-even rounding', function (): void {
        // 50 bps of 100,000,001 = 500,000.005 -> ties to even -> 500,000
        expect(Money::ofSubunits(100_000_001, Currency::NGN)->basisPoints(50)->subunits)
            ->toBe(500_000);

        // 60 bps of 150,000,000 = 900,000 exactly
        expect(Money::ofSubunits(150_000_000, Currency::NGN)->basisPoints(60)->subunits)
            ->toBe(900_000);

        expect(Money::ofSubunits(1_000, Currency::NGN)->basisPoints(0)->subunits)->toBe(0);
    });

    it('converts between currencies at an exact rate', function (): void {
        $ngn = Money::ofSubunits(1_000_000, Currency::NGN);
        $cny = $ngn->convertTo(Currency::CNY, '0.005');

        expect($cny->currency)->toBe(Currency::CNY);
        expect($cny->subunits)->toBe(5_000);
    });

    it('rounds a fractional conversion half-even', function (): void {
        // 1 subunit * 0.5 = 0.5 -> ties to even -> 0
        expect(Money::ofSubunits(1, Currency::NGN)->convertTo(Currency::CNY, '0.5')->subunits)
            ->toBe(0);

        // 3 * 0.5 = 1.5 -> ties to even -> 2
        expect(Money::ofSubunits(3, Currency::NGN)->convertTo(Currency::CNY, '0.5')->subunits)
            ->toBe(2);
    });

    it('rejects a non-numeric rate rather than silently yielding zero', function (): void {
        // BCMath treats garbage as 0, which would post a zero-value movement
        // instead of failing. The guard turns that into an exception.
        expect(fn () => Money::ofSubunits(1_000, Currency::NGN)->convertTo(Currency::CNY, 'abc'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('serialises without exposing a float', function (): void {
        $json = Money::ofSubunits(150_075, Currency::NGN)->jsonSerialize();

        expect($json['subunits'])->toBe(150_075)->toBeInt();
        expect($json['currency'])->toBe('NGN');
        expect($json['formatted'])->toBe('1500.75')->toBeString();
    });
});

describe('Currency', function (): void {
    it('uses two-decimal subunits for both currencies', function (): void {
        expect(Currency::NGN->exponent())->toBe(2);
        expect(Currency::CNY->exponent())->toBe(2);
        expect(Currency::NGN->subunitFactor())->toBe('100');
        expect(Currency::NGN->subunitName())->toBe('kobo');
        expect(Currency::CNY->subunitName())->toBe('fen');
    });
});

describe('Decimal guard', function (): void {
    it('accepts numeric strings', function (): void {
        expect(Decimal::guard('1.5'))->toBe('1.5');
        expect(Decimal::guardInteger('-42'))->toBe('-42');
    });

    it('rejects anything BCMath would silently treat as zero', function (): void {
        expect(fn () => Decimal::guard(''))->toThrow(InvalidArgumentException::class);
        expect(fn () => Decimal::guard('abc'))->toThrow(InvalidArgumentException::class);
        expect(fn () => Decimal::guardInteger('1.5'))->toThrow(InvalidArgumentException::class);
    });
});
