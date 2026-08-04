<?php

declare(strict_types=1);

namespace App\Domain\Swap;

use App\Domain\Money\Money;

/**
 * The fully-resolved arithmetic of a swap, computed once and then posted.
 *
 * Holding it as a value object means the numbers that reach the ledger are the
 * exact numbers that were quoted — there is no opportunity to recompute a rate
 * halfway through and post something the caller never saw.
 */
final class SwapQuote
{
    public function __construct(
        public readonly Money $grossSource,
        public readonly Money $fee,
        public readonly Money $netSource,
        public readonly Money $target,
        public readonly string $rate,
        public readonly int $slippageBps,
        public readonly bool $rateWasStale,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toMetadata(): array
    {
        return [
            'rate' => $this->rate,
            'slippage_bps' => $this->slippageBps,
            'rate_was_stale' => $this->rateWasStale,
            'gross_source_subunits' => $this->grossSource->subunits,
            'fee_subunits' => $this->fee->subunits,
            'net_source_subunits' => $this->netSource->subunits,
            'target_subunits' => $this->target->subunits,
            'source_currency' => $this->grossSource->currency->value,
            'target_currency' => $this->target->currency->value,
        ];
    }
}
