<?php

declare(strict_types=1);

use App\Domain\Money\Currency;
use App\Jobs\ProcessSettlement;
use App\Models\SettlementEvent;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    seedSystemWallets();
    $this->user = User::factory()->withTotp()->withWallets()->create();
});

/**
 * Sign a payload exactly as the provider would.
 *
 * @param  array<string, mixed>  $payload
 * @return array<string, string>
 */
function signedHeaders(array $payload, ?int $timestamp = null, ?string $secret = null): array
{
    $timestamp ??= now()->getTimestamp();
    $secret ??= (string) config('tupay.webhooks.secret');
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    return [
        'X-Tupay-Timestamp' => (string) $timestamp,
        'X-Tupay-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, $secret),
    ];
}

/**
 * @param  array<string, mixed>  $payload
 */
function postWebhook(array $payload, ?array $headers = null): TestResponse
{
    $headers ??= signedHeaders($payload);

    // The signature covers the RAW body, so the request must be sent with the
    // exact bytes that were signed rather than a re-encoded array.
    return test()->call(
        'POST',
        '/api/webhooks/settlement',
        [], [], [],
        array_merge(
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            collect($headers)->mapWithKeys(fn ($v, $k): array => ['HTTP_'.str_replace('-', '_', strtoupper($k)) => $v])->all(),
        ),
        json_encode($payload, JSON_THROW_ON_ERROR),
    );
}

describe('signature verification', function (): void {
    it('rejects a request with no signature headers', function (): void {
        postWebhook(['provider_reference' => 'x'], [])
            ->assertUnauthorized()
            ->assertJsonPath('error', 'webhook_signature_missing');
    });

    it('rejects a forged signature', function (): void {
        $payload = [
            'provider_reference' => 'ref-1', 'status' => 'COMPLETED',
            'user_id' => $this->user->id, 'currency' => 'NGN', 'amount_subunits' => 1000,
        ];

        postWebhook($payload, signedHeaders($payload, null, 'the-wrong-secret'))
            ->assertUnauthorized()
            ->assertJsonPath('error', 'webhook_signature_invalid');
    });

    it('rejects a stale timestamp even when correctly signed', function (): void {
        $payload = [
            'provider_reference' => 'ref-2', 'status' => 'COMPLETED',
            'user_id' => $this->user->id, 'currency' => 'NGN', 'amount_subunits' => 1000,
        ];

        // Correctly signed, but well outside the replay tolerance.
        postWebhook($payload, signedHeaders($payload, now()->getTimestamp() - 3600))
            ->assertUnauthorized()
            ->assertJsonPath('error', 'webhook_timestamp_expired');
    });

    it('accepts a correctly signed request', function (): void {
        postWebhook([
            'provider_reference' => 'ref-3', 'status' => 'INITIATED',
            'user_id' => $this->user->id, 'currency' => 'NGN', 'amount_subunits' => 1000,
        ])->assertStatus(202);
    });
});

describe('idempotency', function (): void {
    it('credits the wallet exactly once for a repeated COMPLETED', function (): void {
        $payload = [
            'provider_reference' => 'settle-dup', 'status' => 'COMPLETED',
            'user_id' => $this->user->id, 'currency' => 'NGN', 'amount_subunits' => 250_000,
        ];

        postWebhook($payload)->assertStatus(202);

        expect($this->user->walletFor(Currency::NGN)?->balance()->subunits)->toBe(250_000);

        // Same reference, same status, delivered four more times.
        foreach (range(1, 4) as $ignored) {
            postWebhook($payload)->assertOk()->assertJsonPath('outcome', 'duplicate');
        }

        // Still credited once.
        expect($this->user->walletFor(Currency::NGN)?->balance()->subunits)->toBe(250_000);
        expect(SettlementEvent::query()->count())->toBe(1);
        expect(ledgerNetByCurrency()['NGN'])->toBe(0);
    });
});

describe('out-of-order delivery', function (): void {
    it('does not walk backwards when INITIATED arrives after COMPLETED', function (): void {
        $base = [
            'provider_reference' => 'settle-ooo',
            'user_id' => $this->user->id,
            'currency' => 'NGN',
            'amount_subunits' => 100_000,
        ];

        // The terminal state arrives first.
        postWebhook([...$base, 'status' => 'COMPLETED'])->assertStatus(202);

        expect($this->user->walletFor(Currency::NGN)?->balance()->subunits)->toBe(100_000);

        // The earlier states arrive late and must not regress the settlement
        // or trigger a second credit.
        postWebhook([...$base, 'status' => 'INITIATED'])
            ->assertStatus(202)
            ->assertJsonPath('outcome', 'ignored_out_of_order');

        postWebhook([...$base, 'status' => 'PROCESSING'])
            ->assertStatus(202)
            ->assertJsonPath('outcome', 'ignored_out_of_order');

        $event = SettlementEvent::query()->where('provider_reference', 'settle-ooo')->firstOrFail();

        expect($event->status)->toBe('COMPLETED');
        expect($event->seen_statuses)->toContain('INITIATED', 'PROCESSING', 'COMPLETED');

        // Credited exactly once despite three deliveries.
        expect($this->user->walletFor(Currency::NGN)?->balance()->subunits)->toBe(100_000);
        expect(ledgerNetByCurrency()['NGN'])->toBe(0);
    });

    it('advances forward through the normal ordering', function (): void {
        $base = [
            'provider_reference' => 'settle-fwd',
            'user_id' => $this->user->id,
            'currency' => 'CNY',
            'amount_subunits' => 4_200,
        ];

        postWebhook([...$base, 'status' => 'INITIATED'])->assertStatus(202);
        expect($this->user->walletFor(Currency::CNY)?->balance()->subunits)->toBe(0);

        postWebhook([...$base, 'status' => 'PROCESSING'])
            ->assertStatus(202)
            ->assertJsonPath('outcome', 'applied');
        expect($this->user->walletFor(Currency::CNY)?->balance()->subunits)->toBe(0);

        // Only COMPLETED moves money.
        postWebhook([...$base, 'status' => 'COMPLETED'])
            ->assertStatus(202)
            ->assertJsonPath('outcome', 'applied');

        expect($this->user->walletFor(Currency::CNY)?->balance()->subunits)->toBe(4_200);
    });

    it('never credits a FAILED settlement', function (): void {
        postWebhook([
            'provider_reference' => 'settle-failed', 'status' => 'FAILED',
            'user_id' => $this->user->id, 'currency' => 'NGN', 'amount_subunits' => 999_999,
        ])->assertStatus(202);

        expect($this->user->walletFor(Currency::NGN)?->balance()->subunits)->toBe(0);
    });
});

describe('asynchronous processing', function (): void {
    it('queues the ledger posting rather than doing it inline', function (): void {
        Queue::fake();

        postWebhook([
            'provider_reference' => 'settle-queued', 'status' => 'COMPLETED',
            'user_id' => $this->user->id, 'currency' => 'NGN', 'amount_subunits' => 5_000,
        ])->assertStatus(202)->assertJsonPath('queued', true);

        Queue::assertPushed(ProcessSettlement::class);
    });
});
