<?php

declare(strict_types=1);

namespace App\Services\Swap;

use App\Domain\Ledger\LedgerException;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Redis distributed locks acquired in a deterministic order.
 *
 * DEADLOCK PREVENTION
 * -------------------
 * Two swaps that touch the same pair of wallets in opposite directions are the
 * classic deadlock: A locks wallet X and waits for Y, B locks Y and waits for
 * X, and neither ever proceeds. The fix is not cleverness, it is a total
 * order — every participant acquires the same resources in the same sequence,
 * so a cycle cannot form. Here that order is a plain alphabetical sort of the
 * lock keys, which is why wallet identifiers are UUID strings.
 *
 * Each lock carries a random owner token. Releasing compares that token inside
 * a Lua script so a process whose lock already expired cannot delete a lock
 * that a different process has since acquired — deleting by key alone is a
 * well-known way to corrupt exactly the invariant the lock exists to protect.
 */
final class DistributedLock
{
    /** Compare-and-delete: only the owner may release. */
    private const RELEASE_SCRIPT = <<<'LUA'
        if redis.call("get", KEYS[1]) == ARGV[1] then
            return redis.call("del", KEYS[1])
        else
            return 0
        end
    LUA;

    /** @var list<array{key: string, token: string}> */
    private array $held = [];

    /**
     * Acquire every key, in strict alphabetical order.
     *
     * @param  list<string>  $keys
     *
     * @throws LedgerException when any key is already held
     */
    public function acquireAll(array $keys): void
    {
        // The total order that makes deadlock impossible.
        sort($keys, SORT_STRING);

        $ttl = (int) config('tupay.lock.ttl', 10);
        $prefix = (string) config('tupay.lock.prefix', 'lock:');

        foreach ($keys as $key) {
            $token = (string) Str::uuid();
            $namespaced = $prefix.$key;

            // command() rather than the magic set(): the Redis facade is typed
            // against the phpredis extension, whose set() signature differs
            // from the variadic Redis-protocol form. Going through command()
            // keeps this correct under BOTH predis and phpredis.
            //
            // NX means "only if absent" — that is what makes acquisition
            // atomic. EX bounds how long a crashed holder can wedge the wallet.
            $acquired = Redis::command('set', [$namespaced, $token, 'EX', $ttl, 'NX']);

            // predis returns null when NX declines; phpredis returns false.
            if ($acquired === null || $acquired === false) {
                // Fail fast rather than block. A waiter would still have to
                // re-read the balance afterwards, so queueing buys nothing but
                // latency — and the spec wants losers to see 409.
                $this->releaseAll();

                throw LedgerException::lockContention();
            }

            $this->held[] = ['key' => $namespaced, 'token' => $token];
        }
    }

    /**
     * Release in reverse acquisition order. Always safe to call twice.
     */
    public function releaseAll(): void
    {
        foreach (array_reverse($this->held) as $lock) {
            Redis::command('eval', [self::RELEASE_SCRIPT, 1, $lock['key'], $lock['token']]);
        }

        $this->held = [];
    }

    /**
     * @return list<string>
     */
    public function heldKeys(): array
    {
        return array_column($this->held, 'key');
    }
}
