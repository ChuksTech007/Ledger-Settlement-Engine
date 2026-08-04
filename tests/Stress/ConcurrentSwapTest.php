<?php

declare(strict_types=1);

use App\Domain\Money\Currency;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Services\StepUp\ElevatedActionTokenService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Utils;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * THE RACE CONDITION TEST
 *
 * Ten concurrent POST /api/swap requests for a user funded for exactly one.
 * Required outcome: exactly 1x 200, exactly 9x 409/422, zero overdraft, and a
 * ledger that still balances to the subunit.
 *
 * WHY THIS TALKS TO A REAL SERVER
 * -------------------------------
 * Laravel's in-process test client executes requests sequentially inside one
 * PHP process. A race condition cannot occur there, so a "concurrency test"
 * written against it passes unconditionally and proves nothing at all. Real
 * parallelism needs real concurrent processes, which means real HTTP.
 *
 * WARNING ABOUT `php artisan serve`
 * ---------------------------------
 * The PHP CLI server is single-threaded unless PHP_CLI_SERVER_WORKERS is set,
 * and that variable is POSIX-only — it is ignored on Windows. Against a
 * single-worker server the ten requests are handled one after another, and
 * this test will still go green while having tested nothing.
 *
 * The suite therefore MEASURES whether genuine overlap occurred and reports it,
 * so a green run on an inadequate server cannot be mistaken for proof.
 */
beforeEach(function (): void {
    // Point at the same database the server is using. The test database is
    // irrelevant here: the server process reads .env, not phpunit.xml.
    $database = env('STRESS_TEST_DB', 'tupay');

    config([
        'database.connections.pgsql.database' => $database,
        'database.connections.pgsql.port' => env('STRESS_TEST_DB_PORT', 5433),
    ]);

    DB::purge('pgsql');

    // The EATs this test mints must land in the SAME Redis database the server
    // reads from. phpunit.xml isolates the suite on db 15, but the server boots
    // from .env on db 0 — without this override every token would be minted
    // where the server cannot see it, and all ten swaps would 401.
    config([
        'database.redis.default.database' => (int) env('STRESS_TEST_REDIS_DB', 0),
    ]);

    app('redis')->purge('default');

    $this->baseUrl = rtrim((string) env('STRESS_TEST_BASE_URL', 'http://127.0.0.1:8000'), '/');

    $this->client = new Client([
        'base_uri' => $this->baseUrl,
        // Generous: on a single-threaded dev server the ten requests queue, and
        // the last one waits for all nine ahead of it. A tight timeout would
        // turn "slow server" into a spurious ledger failure.
        'timeout' => (int) env('STRESS_TEST_TIMEOUT', 120),
        'http_errors' => false,
    ]);

    // Fail fast, with a useful message, if nothing is listening.
    try {
        $this->client->get('/up');
    } catch (ConnectException) {
        $this->markTestSkipped(
            "No server reachable at {$this->baseUrl}. Start one with:\n".
            "  php artisan serve\n".
            'On Linux/CI use PHP_CLI_SERVER_WORKERS=10 for genuine parallelism.'
        );
    }
});

it('allows exactly one of ten concurrent swaps to succeed', function (): void {
    $swapAmount = 1_000_000;      // 10,000.00 NGN
    $concurrency = 10;

    // ---------------------------------------------------------------- set-up
    // Committed, not wrapped in a transaction: the server must be able to see
    // this data from its own connection.
    $email = 'race-'.Str::random(8).'@tupay.test';

    $secret = (new Google2FA)->generateSecretKey(32);

    /** @var User $user */
    $user = User::query()->create([
        'name' => 'Race Condition Probe',
        'email' => $email,
        'password' => 'password',
        'email_verified_at' => now(),
        'totp_secret' => $secret,
        'totp_confirmed_at' => now(),
    ]);

    foreach (Currency::cases() as $currency) {
        Wallet::query()->firstOrCreate(
            ['currency' => $currency->value, 'type' => Wallet::TYPE_SYSTEM],
            ['user_id' => null],
        );

        Wallet::query()->create([
            'user_id' => $user->id,
            'currency' => $currency->value,
            'type' => Wallet::TYPE_USER,
        ]);
    }

    // Fund EXACTLY one swap. Not a kobo more.
    fundWallet($user, Currency::NGN, $swapAmount);

    $ngnWallet = $user->walletFor(Currency::NGN);
    expect($ngnWallet?->balance()->subunits)->toBe($swapAmount);

    // Pre-warm the rate cache.
    //
    // Two reasons, both essential:
    //
    //   1. The mock provider is served by the SAME application. On a
    //      single-threaded server a swap that tries to fetch a rate would be
    //      waiting on a request the server cannot begin until the swap
    //      finishes — a self-deadlock that surfaces as 503 rate_unavailable.
    //   2. A pinned rate makes the arithmetic deterministic, so the ledger
    //      assertions are exact rather than dependent on provider jitter.
    //
    // This is precisely the situation stale-while-revalidate exists to handle:
    // a warm cache means the swap path never blocks on the network.
    pinRate('0.005');

    $params = [
        'source_currency' => 'NGN',
        'target_currency' => 'CNY',
        'amount_subunits' => $swapAmount,
    ];

    // A Sanctum token the server will accept.
    $bearer = $user->createToken('stress-test')->plainTextToken;

    // Ten independent single-use EATs. Minted directly rather than via the
    // TOTP challenge because a TOTP code is itself single-use, so ten codes
    // would need five minutes of wall clock. The elevation logic is covered
    // exhaustively in StepUpAuthTest; what is under test here is the ledger.
    $tokens = app(ElevatedActionTokenService::class);

    $eats = collect(range(1, $concurrency))
        ->map(fn (): string => $tokens->issue($user, 'swap', $params)['token'])
        ->all();

    expect($eats)->toHaveCount($concurrency);
    expect(array_unique($eats))->toHaveCount($concurrency);

    // ------------------------------------------------------------- the race
    $timings = [];

    $batchStart = microtime(true);

    $promises = [];

    foreach ($eats as $i => $eat) {
        $promises[$i] = $this->client->postAsync('/api/swap', [
            'headers' => [
                'Authorization' => 'Bearer '.$bearer,
                'X-Elevated-Action-Token' => $eat,
                'Accept' => 'application/json',
            ],
            'json' => $params,
            'on_stats' => function ($stats) use (&$timings, $i): void {
                $timings[$i] = $stats->getTransferTime();
            },
        ]);
    }

    $responses = Utils::settle($promises)->wait();

    $batchDuration = microtime(true) - $batchStart;

    // ---------------------------------------------------------- assertions
    $statuses = [];
    $transportFailures = [];

    foreach ($responses as $i => $result) {
        if ($result['state'] !== 'fulfilled') {
            // A transport-level failure is not a valid ledger outcome. Surface
            // the reason rather than letting it masquerade as a rejection,
            // because a timeout would otherwise be miscounted as the ledger
            // correctly refusing the swap.
            $transportFailures[$i] = substr((string) ($result['reason']?->getMessage() ?? 'unknown'), 0, 120);

            continue;
        }

        $statuses[] = $result['value']->getStatusCode();
    }

    if ($transportFailures !== []) {
        fwrite(STDERR, sprintf(
            "\n  [concurrency] %d/%d requests failed at the transport layer:\n    %s\n".
            "  This usually means the server serialised the requests and the client timed out.\n".
            "  `php artisan serve` is single-threaded; PHP_CLI_SERVER_WORKERS is POSIX-only.\n",
            count($transportFailures),
            $concurrency,
            implode("\n    ", $transportFailures),
        ));
    }

    expect($transportFailures)->toBeEmpty(
        'Every request must reach the application. Transport failures mean the '.
        'server could not serve the load, so the race was never actually run.'
    );

    $counts = array_count_values($statuses);

    $succeeded = $counts[200] ?? 0;
    $rejected = ($counts[409] ?? 0) + ($counts[422] ?? 0);

    // ------------------------------------------------------- diagnostics
    //
    // Was this a genuine race, or did the server quietly serialise the ten
    // requests? Client-side timing alone cannot answer that: if the server
    // handles requests one at a time, every request still *starts* at t=0 and
    // simply waits, so their transfer times overlap on the client regardless.
    //
    // The honest signal is the SHAPE of the failures:
    //
    //   409 wallet_locked  -> a contender found the lock already held, which
    //                         can only happen if another request was in flight
    //                         at the same moment. Proof of a real race.
    //   422 insufficient_funds -> the contender ran after the winner had
    //                         already committed. That is what serialised
    //                         execution looks like.
    //
    // A run consisting entirely of 422s is still CORRECT, but it has not
    // exercised the concurrency guard.
    $sumOfTransfers = array_sum($timings);
    $overlapRatio = $batchDuration > 0 ? $sumOfTransfers / $batchDuration : 0.0;

    $lockRejections = $counts[409] ?? 0;
    $fundRejections = $counts[422] ?? 0;

    fwrite(STDERR, sprintf(
        "\n  [concurrency] statuses=%s wall=%.3fs client-overlap=%.1fx\n".
        "  [concurrency] 409 lock-contention=%d  422 insufficient-funds=%d\n".
        "  [concurrency] %s\n",
        json_encode($counts),
        $batchDuration,
        $overlapRatio,
        $lockRejections,
        $fundRejections,
        $lockRejections > 0
            ? 'GENUINE RACE: at least one request was rejected by the distributed lock.'
            : 'WARNING: no lock contention observed — the server may have serialised '.
              'these requests. Result is correct but does not prove concurrency safety.',
    ));

    expect($succeeded)->toBe(1, 'Exactly one swap must succeed.');
    expect($rejected)->toBe($concurrency - 1, 'Every other swap must be rejected with 409 or 422.');
    expect($succeeded + $rejected)->toBe($concurrency, 'No request may fail for any other reason.');

    // ------------------------------------------------- ledger integrity
    $finalNgn = $ngnWallet->fresh()?->balance()->subunits;

    expect($finalNgn)->toBe(0, 'The wallet must be drained to exactly zero — never negative.');
    expect($finalNgn)->toBeGreaterThanOrEqual(0, 'No overdraft may occur under any circumstances.');

    // Exactly one swap transaction was posted.
    $swapEntries = LedgerEntry::query()
        ->where('wallet_id', $ngnWallet->id)
        ->whereHas('transaction', fn ($q) => $q->where('type', 'swap'))
        ->count();

    expect($swapEntries)->toBe(1, 'Exactly one debit leg may exist on the source wallet.');

    // The whole ledger still nets to zero in every currency, to the subunit.
    $net = DB::table('ledger_entries')
        ->selectRaw('currency, SUM(signed_amount) AS net')
        ->groupBy('currency')
        ->pluck('net', 'currency');

    foreach ($net as $currency => $value) {
        expect((int) $value)->toBe(0, "Ledger must balance exactly for {$currency}.");
    }

    // ------------------------------------------------------------- cleanup
    // Targeted, not flushdb(): this runs against the development Redis, so
    // wiping the whole database would destroy the rate cache and any other
    // live keys the developer is using.
    DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->delete();

    $leftoverEats = Redis::keys(config('tupay.eat.redis_prefix').'*');

    if ($leftoverEats !== []) {
        Redis::del(...$leftoverEats);
    }
});
