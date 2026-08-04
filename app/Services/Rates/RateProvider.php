<?php

declare(strict_types=1);

namespace App\Services\Rates;

use App\Domain\Ledger\LedgerException;
use App\Domain\Money\Currency;
use App\Domain\Money\Decimal;
use App\Jobs\RefreshExchangeRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * NGN/CNY rates with a stale-while-revalidate cache.
 *
 * Three regimes, keyed on the age of the cached value:
 *
 *   age < fresh_ttl              -> serve from cache, do nothing else
 *   fresh_ttl <= age < stale_ttl -> serve the STALE value immediately and
 *                                   dispatch one background refresh
 *   age >= stale_ttl (or empty)  -> fetch synchronously; we have nothing
 *                                   safe left to serve
 *
 * The point of the middle regime is that a swap never pays the latency of an
 * upstream call just because a timer elapsed. The refresh is guarded by a
 * short-lived Redis lock so that when a thousand requests arrive the instant a
 * rate goes stale, exactly one of them refreshes it — the classic cache
 * stampede, which is precisely what "stale-while-revalidate" exists to stop.
 */
final class RateProvider
{
    /**
     * @return array{rate: string, fetched_at: int, stale: bool}
     */
    public function quote(Currency $from, Currency $to): array
    {
        $cached = $this->readCache();
        $now = Carbon::now()->getTimestamp();

        if ($cached !== null) {
            $age = $now - $cached['fetched_at'];

            if ($age < $this->freshTtl()) {
                return [...$cached, 'stale' => false, 'rate' => $this->orient($cached['rate'], $from, $to)];
            }

            if ($age < $this->staleTtl()) {
                // Serve stale, refresh behind the scenes.
                $this->dispatchRefresh();

                return [...$cached, 'stale' => true, 'rate' => $this->orient($cached['rate'], $from, $to)];
            }
        }

        // Nothing usable left: we must block on the provider.
        $fresh = $this->fetchAndStore();

        return [...$fresh, 'stale' => false, 'rate' => $this->orient($fresh['rate'], $from, $to)];
    }

    /**
     * Fetch from the upstream provider and write through to Redis.
     *
     * @return array{rate: string, fetched_at: int}
     */
    public function fetchAndStore(): array
    {
        try {
            $response = Http::timeout((int) config('tupay.rates.timeout', 3))
                ->acceptJson()
                ->get((string) config('tupay.rates.endpoint'));

            if (! $response->successful()) {
                throw new \RuntimeException("Rate provider returned HTTP {$response->status()}");
            }

            /** @var array{rate?: mixed} $body */
            $body = $response->json();

            $rate = isset($body['rate']) ? (string) $body['rate'] : '';

            // A zero, negative, or malformed rate must never reach the swap
            // path: BCMath would accept it silently and post a zero movement.
            if (preg_match('/^\d+(\.\d+)?$/', $rate) !== 1) {
                throw new \RuntimeException("Rate provider returned an unusable rate: {$rate}");
            }

            if (bccomp(Decimal::guard($rate, 'exchange rate'), '0', 18) <= 0) {
                throw new \RuntimeException("Rate provider returned a non-positive rate: {$rate}");
            }
        } catch (Throwable $e) {
            Log::warning('Exchange rate fetch failed', ['exception' => $e->getMessage()]);

            // Last resort: if any cached value survives at all, prefer serving
            // something stale over failing the swap outright.
            $cached = $this->readCache();

            if ($cached !== null) {
                return $cached;
            }

            throw LedgerException::rateUnavailable();
        }

        $payload = [
            'rate' => $rate,
            'fetched_at' => Carbon::now()->getTimestamp(),
        ];

        // TTL is the stale window, not the fresh window: the value must remain
        // readable while it is stale, otherwise there is nothing to revalidate.
        Redis::command('set', [
            (string) config('tupay.rates.cache_key'),
            json_encode($payload, JSON_THROW_ON_ERROR),
            'EX',
            $this->staleTtl(),
        ]);

        return $payload;
    }

    /**
     * @return array{rate: string, fetched_at: int}|null
     */
    private function readCache(): ?array
    {
        /** @var string|null $raw */
        $raw = Redis::get((string) config('tupay.rates.cache_key'));

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        /** @var array{rate?: mixed, fetched_at?: mixed}|null $decoded */
        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || ! isset($decoded['rate'], $decoded['fetched_at'])) {
            return null;
        }

        return [
            'rate' => (string) $decoded['rate'],
            'fetched_at' => (int) $decoded['fetched_at'],
        ];
    }

    /**
     * Dispatch at most one refresh per stale window.
     *
     * SET NX is what makes this single-flight: the first caller to claim the
     * lock queues the job, everyone else silently serves stale.
     */
    private function dispatchRefresh(): void
    {
        $claimed = Redis::command('set', [
            (string) config('tupay.rates.refresh_lock_key'),
            '1',
            'EX',
            $this->freshTtl(),
            'NX',
        ]);

        // predis returns null when NX declines; phpredis returns false.
        if ($claimed === null || $claimed === false) {
            return;
        }

        RefreshExchangeRate::dispatch();
    }

    /**
     * The cached rate is stored as NGN -> CNY. Inverting for the opposite
     * direction is done with bcdiv at high scale so the round trip does not
     * lose subunits.
     */
    private function orient(string $rate, Currency $from, Currency $to): string
    {
        if ($from === Currency::NGN && $to === Currency::CNY) {
            return $rate;
        }

        if ($from === Currency::CNY && $to === Currency::NGN) {
            return bcdiv('1', $rate, (int) config('tupay.rates.scale', 18));
        }

        return '1';
    }

    private function freshTtl(): int
    {
        return (int) config('tupay.rates.fresh_ttl', 30);
    }

    private function staleTtl(): int
    {
        return (int) config('tupay.rates.stale_ttl', 300);
    }
}
