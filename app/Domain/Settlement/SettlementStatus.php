<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

/**
 * The settlement lifecycle, as a ranked state machine.
 *
 * Webhooks arrive out of order — a COMPLETED can land before the INITIATED
 * that logically precedes it, because the provider retries independently per
 * event and the network reorders freely. Comparing *rank* rather than trusting
 * arrival order means a late INITIATED cannot walk a settlement backwards out
 * of COMPLETED and cause a re-credit.
 */
enum SettlementStatus: string
{
    case Initiated = 'INITIATED';
    case Processing = 'PROCESSING';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';

    /**
     * Higher rank wins. COMPLETED and FAILED are both terminal and share the
     * top rank, so whichever arrives first is the one that sticks — a provider
     * that sends both for one settlement is contradicting itself, and we keep
     * the first terminal answer rather than flip-flopping.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Initiated => 1,
            self::Processing => 2,
            self::Completed => 3,
            self::Failed => 3,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }

    /**
     * Only a COMPLETED settlement moves money.
     */
    public function shouldPostToLedger(): bool
    {
        return $this === self::Completed;
    }

    /**
     * Whether observing $incoming should advance a settlement currently at $this.
     */
    public function supersededBy(self $incoming): bool
    {
        return $incoming->rank() > $this->rank();
    }
}
