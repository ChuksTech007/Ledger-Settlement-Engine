<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Rates\RateProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * The "revalidate" half of stale-while-revalidate.
 *
 * Failure here is deliberately non-fatal: callers are already being served a
 * stale rate, so a failed refresh degrades freshness rather than availability.
 */
final class RefreshExchangeRate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 2;

    public function handle(RateProvider $rates): void
    {
        $rates->fetchAndStore();
    }

    public function failed(Throwable $e): void
    {
        report($e);
    }
}
