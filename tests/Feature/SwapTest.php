<?php

declare(strict_types=1);

use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Swap\SlippageCalculator;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Services\StepUp\ElevatedActionTokenService;
use App\Services\Swap\DistributedLock;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function (): void {
    seedSystemWallets();
    pinRate('0.005'); // 1 kobo -> 0.005 fen, i.e. 1 NGN = 0.5 CNY

    $this->secret = (new Google2FA)->generateSecretKey(32);
    $this->user = User::factory()->withTotp($this->secret)->create();

    fundWallet($this->user, Currency::NGN, 100_000_000); // 1,000,000.00 NGN
    fundWallet($this->user, Currency::CNY, 0);

    $this->tokens = app(ElevatedActionTokenService::class);
});

/**
 * Mint an EAT directly rather than via the TOTP challenge.
 *
 * The challenge flow is covered exhaustively in StepUpAuthTest. Here it would
 * only be a rate limiter on how many swaps a test can perform, since a TOTP
 * code is single-use and one 30-second window yields one code.
 *
 * @param  array<string, mixed>  $params
 */
function eatFor(User $user, array $params): string
{
    return app(ElevatedActionTokenService::class)->issue($user, 'swap', $params)['token'];
}

/**
 * @param  array<string, mixed>  $params
 */
function swapRequest(User $user, array $params, ?string $token = null): TestResponse
{
    $token ??= eatFor($user, $params);

    return test()
        ->actingAs($user, 'sanctum')
        ->withHeader('X-Elevated-Action-Token', $token)
        ->postJson('/api/swap', $params);
}

describe('swap authorisation', function (): void {
    it('rejects a swap with no elevated token', function (): void {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/swap', [
                'source_currency' => 'NGN',
                'target_currency' => 'CNY',
                'amount_subunits' => 100_000,
            ])
            ->assertUnauthorized()
            ->assertJsonPath('error', 'elevated_token_missing');
    });

    it('rejects a swap whose amount differs from the approved one', function (): void {
        $approved = ['source_currency' => 'NGN', 'target_currency' => 'CNY', 'amount_subunits' => 100_000];
        $token = eatFor($this->user, $approved);

        // Token approved 100,000 kobo; request attempts 50,000,000.
        swapRequest($this->user, [...$approved, 'amount_subunits' => 50_000_000], $token)
            ->assertStatus(422)
            ->assertJsonPath('error', 'elevated_token_action_mismatch');
    });

    it('rejects a replayed elevated token', function (): void {
        $params = ['source_currency' => 'NGN', 'target_currency' => 'CNY', 'amount_subunits' => 100_000];
        $token = eatFor($this->user, $params);

        swapRequest($this->user, $params, $token)->assertOk();

        swapRequest($this->user, $params, $token)
            ->assertUnauthorized()
            ->assertJsonPath('error', 'elevated_token_replayed');
    });
});

describe('swap execution', function (): void {
    it('posts four balanced legs and moves both balances', function (): void {
        $params = ['source_currency' => 'NGN', 'target_currency' => 'CNY', 'amount_subunits' => 1_000_000];

        $response = swapRequest($this->user, $params)->assertOk();

        $transaction = LedgerTransaction::query()->where('type', 'swap')->firstOrFail();

        expect($transaction->entries()->count())->toBe(4);

        // 1,000,000 kobo is below the slippage threshold, so no fee.
        expect($response->json('slippage_bps'))->toBe(0);

        // 1,000,000 kobo * 0.005 = 5,000 fen
        expect($response->json('target.credited_subunits'))->toBe(5_000);

        $this->user->refresh();
        expect($this->user->walletFor(Currency::NGN)?->balance()->subunits)->toBe(99_000_000);
        expect($this->user->walletFor(Currency::CNY)?->balance()->subunits)->toBe(5_000);
    });

    it('keeps the whole ledger zero-sum per currency', function (): void {
        swapRequest($this->user, [
            'source_currency' => 'NGN', 'target_currency' => 'CNY', 'amount_subunits' => 2_500_000,
        ])->assertOk();

        expect(ledgerNetByCurrency())->toEqual(['NGN' => 0, 'CNY' => 0]);
    });

    it('refuses to overdraw the wallet', function (): void {
        $params = [
            'source_currency' => 'NGN',
            'target_currency' => 'CNY',
            'amount_subunits' => 100_000_001, // one kobo more than the balance
        ];

        swapRequest($this->user, $params)
            ->assertStatus(422)
            ->assertJsonPath('error', 'insufficient_funds');

        // Nothing moved. No CNY entry was written at all, so that currency is
        // absent from the ledger rather than present-and-zero.
        expect($this->user->walletFor(Currency::NGN)?->balance()->subunits)->toBe(100_000_000);
        expect(ledgerNetByCurrency())->toEqual(['NGN' => 0]);
    });

    it('rejects a swap between identical currencies', function (): void {
        // Minted directly: the challenge endpoint refuses to issue a token for
        // an identical currency pair, so reaching the controller's own
        // validation requires bypassing that earlier guard.
        $params = ['source_currency' => 'NGN', 'target_currency' => 'NGN', 'amount_subunits' => 1000];

        swapRequest($this->user, $params)->assertStatus(422);
    });
});

describe('lock contention', function (): void {
    /**
     * Directly exercises the 409 branch.
     *
     * The full race is proven by the Stress suite against a real concurrent
     * server, but that suite only produces a 409 when the operating system
     * actually interleaves two requests — which a single-threaded dev server
     * will not do. Holding the lock explicitly makes the contention path
     * deterministic, so the behaviour is verified on every run rather than
     * only when the scheduler cooperates.
     */
    it('returns 409 when another request already holds the user lock', function (): void {
        $params = ['source_currency' => 'NGN', 'target_currency' => 'CNY', 'amount_subunits' => 100_000];

        // Stand in for a concurrent request that got there first.
        $prefix = (string) config('tupay.lock.prefix');
        Redis::command('set', [$prefix.'user:'.$this->user->id, 'held-by-someone-else', 'EX', 10, 'NX']);

        swapRequest($this->user, $params)
            ->assertStatus(409)
            ->assertJsonPath('error', 'wallet_locked');

        // The contender must not have moved any money.
        expect($this->user->walletFor(Currency::NGN)?->balance()->subunits)->toBe(100_000_000);
    });

    it('returns 409 when a wallet lock is held', function (): void {
        $params = ['source_currency' => 'NGN', 'target_currency' => 'CNY', 'amount_subunits' => 100_000];

        $prefix = (string) config('tupay.lock.prefix');
        $walletId = $this->user->walletFor(Currency::NGN)?->id;

        Redis::command('set', [$prefix.'wallet:'.$walletId, 'held', 'EX', 10, 'NX']);

        swapRequest($this->user, $params)
            ->assertStatus(409)
            ->assertJsonPath('error', 'wallet_locked');
    });

    it('releases every lock it acquired when the swap fails', function (): void {
        $prefix = (string) config('tupay.lock.prefix');

        // Overdraw: acquires all three locks, then fails on the balance check.
        swapRequest($this->user, [
            'source_currency' => 'NGN', 'target_currency' => 'CNY', 'amount_subunits' => 999_999_999,
        ])->assertStatus(422);

        // The finally block must have cleaned up, or this wallet would be
        // wedged until the TTL lapsed.
        expect(Redis::keys($prefix.'*'))->toBeEmpty();
    });

    it('releases every lock it acquired when the swap succeeds', function (): void {
        $prefix = (string) config('tupay.lock.prefix');

        swapRequest($this->user, [
            'source_currency' => 'NGN', 'target_currency' => 'CNY', 'amount_subunits' => 100_000,
        ])->assertOk();

        expect(Redis::keys($prefix.'*'))->toBeEmpty();
    });

    it('acquires locks in strict alphabetical order', function (): void {
        // The anti-deadlock property, asserted on the lock class itself.
        $lock = new DistributedLock;

        $lock->acquireAll([
            'wallet:zzzz-last',
            'user:42',
            'wallet:aaaa-first',
        ]);

        $prefix = (string) config('tupay.lock.prefix');

        expect($lock->heldKeys())->toEqual([
            $prefix.'user:42',
            $prefix.'wallet:aaaa-first',
            $prefix.'wallet:zzzz-last',
        ]);

        $lock->releaseAll();

        expect(Redis::keys($prefix.'*'))->toBeEmpty();
    });

    it('does not release a lock it no longer owns', function (): void {
        $prefix = (string) config('tupay.lock.prefix');

        $lock = new DistributedLock;
        $lock->acquireAll(['user:99']);

        // Simulate the lock having expired and been re-acquired by a different
        // process. Releasing by key alone would delete someone else's lock.
        Redis::command('set', [$prefix.'user:99', 'a-different-owner']);

        $lock->releaseAll();

        expect(Redis::get($prefix.'user:99'))->toBe('a-different-owner');

        Redis::del($prefix.'user:99');
    });
});

describe('tiered slippage', function (): void {
    it('charges nothing at or below 1,000,000 NGN', function (): void {
        $slippage = app(SlippageCalculator::class);

        // "Swaps exceeding 1,000,000 NGN" — exactly at the threshold is free.
        expect($slippage->basisPointsFor(Money::ofSubunits(100_000_000, Currency::NGN)))->toBe(0);
        expect($slippage->basisPointsFor(Money::ofSubunits(99_999_999, Currency::NGN)))->toBe(0);
    });

    it('charges 50 bps just above the threshold', function (): void {
        $slippage = app(SlippageCalculator::class);

        expect($slippage->basisPointsFor(Money::ofSubunits(100_000_001, Currency::NGN)))->toBe(50);
    });

    it('adds 10 bps per additional 500,000 NGN', function (): void {
        $slippage = app(SlippageCalculator::class);

        // 1,500,000 NGN = threshold + exactly one 500,000 tranche
        expect($slippage->basisPointsFor(Money::ofSubunits(150_000_000, Currency::NGN)))->toBe(60);

        // 2,000,000 NGN = threshold + two tranches
        expect($slippage->basisPointsFor(Money::ofSubunits(200_000_000, Currency::NGN)))->toBe(70);

        // 2,499,999.99 NGN = still only two full tranches
        expect($slippage->basisPointsFor(Money::ofSubunits(249_999_999, Currency::NGN)))->toBe(70);
    });

    it('deducts the spread from the converted amount', function (): void {
        fundWallet($this->user, Currency::NGN, 400_000_000); // top up to 5,000,000 NGN

        $params = [
            'source_currency' => 'NGN',
            'target_currency' => 'CNY',
            'amount_subunits' => 150_000_000, // 1,500,000 NGN -> 60 bps
        ];

        $response = swapRequest($this->user, $params)->assertOk();

        expect($response->json('slippage_bps'))->toBe(60);

        // fee = 150,000,000 * 60/10000 = 900,000 kobo
        expect($response->json('source.fee_subunits'))->toBe(900_000);
        expect($response->json('source.net_subunits'))->toBe(149_100_000);

        // 149,100,000 * 0.005 = 745,500 fen
        expect($response->json('target.credited_subunits'))->toBe(745_500);
    });
});
