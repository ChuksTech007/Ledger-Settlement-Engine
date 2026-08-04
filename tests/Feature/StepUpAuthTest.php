<?php

declare(strict_types=1);

use App\Domain\Money\Currency;
use App\Domain\StepUp\StepUpException;
use App\Models\User;
use App\Services\StepUp\ElevatedActionTokenService;
use Illuminate\Support\Facades\Redis;
use PragmaRX\Google2FA\Google2FA;

/**
 * Step-up 2FA: the Elevated Action Token contract.
 *
 * The property under test throughout is that an EAT authorises exactly one
 * action, with exactly one set of parameters, for exactly one user, exactly
 * once. Every test below attacks one of those four words.
 */
const SWAP_PAYLOAD = [
    'source_currency' => 'NGN',
    'target_currency' => 'CNY',
    'amount_subunits' => 5_000_000,
];

/**
 * Authenticate and mint an EAT over real HTTP, returning the token string.
 *
 * @param  array<string, mixed>  $payload
 */
function mintEat(User $user, string $secret, array $payload = SWAP_PAYLOAD): string
{
    $response = test()
        ->actingAs($user, 'sanctum')
        ->postJson('/api/2fa/challenge', [
            'totp_code' => currentTotp($secret),
            'action' => 'swap',
            'action_payload' => $payload,
        ]);

    $response->assertCreated();

    return $response->json('elevated_action_token');
}

describe('login', function (): void {
    it('exchanges credentials for a bearer token', function (): void {
        User::factory()->withTotp()->create([
            'email' => 'trader@tupay.test',
            'password' => 'password',
        ]);

        $this->postJson('/api/login', [
            'email' => 'trader@tupay.test',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonStructure(['token_type', 'access_token', 'user' => ['id', 'email', 'totp_enrolled']])
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.totp_enrolled', true);
    });

    it('returns wallet ids so the ledger endpoint is reachable', function (): void {
        // GET /api/ledger/{wallet_id} needs an id, and no other endpoint
        // exposes one — without this a client could never call it.
        seedSystemWallets();

        $user = User::factory()->withTotp()->withWallets()->create([
            'email' => 'wallets@tupay.test',
            'password' => 'password',
        ]);

        fundWallet($user, Currency::NGN, 250_000);

        $response = $this->postJson('/api/login', [
            'email' => 'wallets@tupay.test',
            'password' => 'password',
        ])->assertOk();

        $wallets = collect($response->json('wallets'));

        expect($wallets)->toHaveCount(2);
        expect($wallets->pluck('currency')->sort()->values()->all())->toEqual(['CNY', 'NGN']);

        $ngn = $wallets->firstWhere('currency', 'NGN');

        // A real, usable UUID — not a placeholder.
        expect($ngn['id'])->toBeString()->toHaveLength(36);

        // The balance is derived from the ledger, not a stored column.
        expect($ngn['balance_subunits'])->toBe(250_000);

        // And the id actually works against the ledger endpoint.
        $this->actingAs($user, 'sanctum')
            ->getJson("/api/ledger/{$ngn['id']}")
            ->assertOk()
            ->assertJsonPath('wallet.balance_subunits', 250_000);
    });

    it('does not expose system wallets to a user', function (): void {
        seedSystemWallets();

        $user = User::factory()->withTotp()->withWallets()->create([
            'email' => 'nosystem@tupay.test',
            'password' => 'password',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'nosystem@tupay.test',
            'password' => 'password',
        ])->assertOk();

        // Only the user's own wallets — the house's liquidity position is not
        // any customer's business.
        expect(collect($response->json('wallets')))->toHaveCount(2);
    });

    it('rejects an incorrect password', function (): void {
        User::factory()->create(['email' => 'trader@tupay.test', 'password' => 'password']);

        $this->postJson('/api/login', [
            'email' => 'trader@tupay.test',
            'password' => 'not-the-password',
        ])->assertStatus(422);
    });

    it('does not leak whether an email is registered', function (): void {
        $this->postJson('/api/login', [
            'email' => 'nobody@tupay.test',
            'password' => 'whatever',
        ])->assertStatus(422);
    });
});

describe('2fa challenge', function (): void {
    it('requires an authenticated bearer token', function (): void {
        $this->postJson('/api/2fa/challenge', [
            'totp_code' => '123456',
            'action' => 'swap',
            'action_payload' => SWAP_PAYLOAD,
        ])->assertUnauthorized();
    });

    it('rejects an invalid TOTP code', function (): void {
        $user = User::factory()->withTotp()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/2fa/challenge', [
                'totp_code' => '000000',
                'action' => 'swap',
                'action_payload' => SWAP_PAYLOAD,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'totp_invalid');
    });

    it('refuses to mint a token for a user with no TOTP enrolment', function (): void {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/2fa/challenge', [
                'totp_code' => '123456',
                'action' => 'swap',
                'action_payload' => SWAP_PAYLOAD,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'totp_not_enrolled');
    });

    it('mints a 60-second token bound to a sha256 action hash', function (): void {
        $secret = (new Google2FA)->generateSecretKey(32);
        $user = User::factory()->withTotp($secret)->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/2fa/challenge', [
                'totp_code' => currentTotp($secret),
                'action' => 'swap',
                'action_payload' => SWAP_PAYLOAD,
            ]);

        $response->assertCreated()
            ->assertJsonPath('expires_in', 60)
            ->assertJsonPath('action', 'swap');

        expect($response->json('action_hash'))->toHaveLength(64);
        expect($response->json('elevated_action_token'))->toContain('.');
    });

    it('validates the action payload before minting', function (): void {
        $secret = (new Google2FA)->generateSecretKey(32);
        $user = User::factory()->withTotp($secret)->create();

        // Same source and target currency is not a swap.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/2fa/challenge', [
                'totp_code' => currentTotp($secret),
                'action' => 'swap',
                'action_payload' => ['source_currency' => 'NGN', 'target_currency' => 'NGN', 'amount_subunits' => 100],
            ])
            ->assertStatus(422);
    });

    it('burns the TOTP code so it cannot mint a second token', function (): void {
        $secret = (new Google2FA)->generateSecretKey(32);
        $user = User::factory()->withTotp($secret)->create();
        $code = currentTotp($secret);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/2fa/challenge', [
                'totp_code' => $code, 'action' => 'swap', 'action_payload' => SWAP_PAYLOAD,
            ])->assertCreated();

        // The same six digits are still inside their validity window, but must
        // not be spendable twice.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/2fa/challenge', [
                'totp_code' => $code, 'action' => 'swap', 'action_payload' => SWAP_PAYLOAD,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'totp_invalid');
    });
});

describe('elevated action token', function (): void {
    beforeEach(function (): void {
        $this->secret = (new Google2FA)->generateSecretKey(32);
        $this->user = User::factory()->withTotp($this->secret)->create();
        $this->tokens = app(ElevatedActionTokenService::class);
    });

    it('is consumable exactly once', function (): void {
        $eat = mintEat($this->user, $this->secret);

        $hash = $this->tokens->consume($eat, $this->user, 'swap', SWAP_PAYLOAD);
        expect($hash)->toHaveLength(64);

        expect(fn () => $this->tokens->consume($eat, $this->user, 'swap', SWAP_PAYLOAD))
            ->toThrow(StepUpException::class);
    });

    it('reports a replay as 401', function (): void {
        $eat = mintEat($this->user, $this->secret);
        $this->tokens->consume($eat, $this->user, 'swap', SWAP_PAYLOAD);

        try {
            $this->tokens->consume($eat, $this->user, 'swap', SWAP_PAYLOAD);
            $this->fail('Replay should have thrown.');
        } catch (StepUpException $e) {
            expect($e->errorCode)->toBe('elevated_token_replayed');
            expect($e->status)->toBe(401);
        }
    });

    it('deletes its Redis key on consumption', function (): void {
        $eat = mintEat($this->user, $this->secret);

        expect(Redis::keys('eat:*'))->toHaveCount(1);

        $this->tokens->consume($eat, $this->user, 'swap', SWAP_PAYLOAD);

        expect(Redis::keys('eat:*'))->toBeEmpty();
    });

    it('refuses parameters other than the ones approved', function (): void {
        $eat = mintEat($this->user, $this->secret);

        // A token approved for ₦50,000 must not authorise ₦5,000,000.
        try {
            $this->tokens->consume($eat, $this->user, 'swap', [
                ...SWAP_PAYLOAD,
                'amount_subunits' => 500_000_000,
            ]);
            $this->fail('Amount substitution should have thrown.');
        } catch (StepUpException $e) {
            expect($e->errorCode)->toBe('elevated_token_action_mismatch');
            expect($e->status)->toBe(422);
        }
    });

    it('refuses a different currency pair', function (): void {
        $eat = mintEat($this->user, $this->secret);

        expect(fn () => $this->tokens->consume($eat, $this->user, 'swap', [
            'source_currency' => 'CNY',
            'target_currency' => 'NGN',
            'amount_subunits' => 5_000_000,
        ]))->toThrow(StepUpException::class);
    });

    it('is insensitive to key order but sensitive to values', function (): void {
        $eat = mintEat($this->user, $this->secret);

        // Same logical payload, keys reordered: canonicalisation must agree.
        $reordered = [
            'amount_subunits' => 5_000_000,
            'target_currency' => 'CNY',
            'source_currency' => 'NGN',
        ];

        expect($this->tokens->consume($eat, $this->user, 'swap', $reordered))->toHaveLength(64);
    });

    it('rejects a forged signature', function (): void {
        $eat = mintEat($this->user, $this->secret);
        [$payload, $signature] = explode('.', $eat);

        try {
            $this->tokens->consume($payload.'.'.strrev($signature), $this->user, 'swap', SWAP_PAYLOAD);
            $this->fail('Forged signature should have thrown.');
        } catch (StepUpException $e) {
            expect($e->errorCode)->toBe('elevated_token_signature_invalid');
            expect($e->status)->toBe(401);
        }
    });

    it('rejects an edited payload', function (): void {
        $eat = mintEat($this->user, $this->secret);
        [$payload, $signature] = explode('.', $eat);

        $decoded = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $decoded['ah'] = hash('sha256', 'a-different-action');
        $edited = rtrim(strtr(base64_encode(json_encode($decoded)), '+/', '-_'), '=');

        expect(fn () => $this->tokens->consume($edited.'.'.$signature, $this->user, 'swap', SWAP_PAYLOAD))
            ->toThrow(StepUpException::class);
    });

    it('cannot be used by a different user', function (): void {
        $eat = mintEat($this->user, $this->secret);
        $attacker = User::factory()->withTotp()->create();

        try {
            $this->tokens->consume($eat, $attacker, 'swap', SWAP_PAYLOAD);
            $this->fail('Cross-user replay should have thrown.');
        } catch (StepUpException $e) {
            expect($e->errorCode)->toBe('elevated_token_subject_mismatch');
            expect($e->status)->toBe(401);
        }
    });

    it('expires after its 60-second window', function (): void {
        $eat = mintEat($this->user, $this->secret);

        $this->travel(61)->seconds();

        try {
            $this->tokens->consume($eat, $this->user, 'swap', SWAP_PAYLOAD);
            $this->fail('Expired token should have thrown.');
        } catch (StepUpException $e) {
            expect($e->errorCode)->toBe('elevated_token_expired');
            expect($e->status)->toBe(401);
        }
    });

    it('rejects a structurally malformed token', function (): void {
        expect(fn () => $this->tokens->consume('not-a-token', $this->user, 'swap', SWAP_PAYLOAD))
            ->toThrow(StepUpException::class);
    });
});
