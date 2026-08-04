<?php

declare(strict_types=1);

namespace App\Domain\Swap;

use App\Domain\Money\Money;

/**
 * Tiered dynamic slippage.
 *
 *   Swaps at or below 1,000,000 NGN  -> no spread
 *   Above that                       -> 0.5% base, plus 0.1% for every
 *                                       additional 500,000 NGN
 *
 * Everything is basis points over integer subunits: 50 bps = 0.5%, 10 bps =
 * 0.1%. Expressing the rate as an integer keeps the fee derivation exact and
 * means the only rounding in the whole calculation happens once, at the end,
 * under banker's rounding.
 *
 * INTERPRETATION (documented in the README): "per additional 500,000 NGN" is
 * read as whole tranches only — the tier increments when a tranche is fully
 * crossed, not partially. At exactly 1,500,000 NGN the rate is 60 bps; at
 * 1,499,999 NGN it is still 50 bps.
 */
final class SlippageCalculator
{
    public function __construct(
        private readonly int $thresholdSubunits,
        private readonly int $baseBps,
        private readonly int $stepSubunits,
        private readonly int $stepBps,
    ) {}

    public static function fromConfig(): self
    {
        /** @var array{threshold_subunits: int, base_bps: int, step_subunits: int, step_bps: int} $config */
        $config = config('tupay.slippage');

        return new self(
            $config['threshold_subunits'],
            $config['base_bps'],
            $config['step_subunits'],
            $config['step_bps'],
        );
    }

    /**
     * Basis points of spread applied to this source amount.
     */
    public function basisPointsFor(Money $sourceAmount): int
    {
        $amount = $sourceAmount->subunits;

        // "Swaps exceeding" — strictly greater than, so a swap of exactly
        // 1,000,000 NGN is not charged.
        if ($amount <= $this->thresholdSubunits) {
            return 0;
        }

        $excess = $amount - $this->thresholdSubunits;

        $additionalTranches = $this->stepSubunits > 0
            ? intdiv($excess, $this->stepSubunits)
            : 0;

        return $this->baseBps + ($additionalTranches * $this->stepBps);
    }

    /**
     * The spread fee itself, in the source currency.
     */
    public function feeFor(Money $sourceAmount): Money
    {
        $bps = $this->basisPointsFor($sourceAmount);

        if ($bps === 0) {
            return Money::zero($sourceAmount->currency);
        }

        return $sourceAmount->basisPoints($bps);
    }
}
