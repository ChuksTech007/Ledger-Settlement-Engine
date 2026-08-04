<?php

declare(strict_types=1);

namespace App\Services\StepUp;

use App\Domain\StepUp\ActionHasher;
use App\Domain\StepUp\StepUpException;
use App\Models\User;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use JsonException;

/**
 * Mints and consumes Elevated Action Tokens.
 *
 * The token is deliberately BOTH stateless and stateful:
 *
 *   - Stateless: an HMAC-SHA256 signature over the payload proves authenticity
 *     and integrity without a lookup. A forged or edited token is rejected
 *     before Redis is touched at all.
 *
 *   - Stateful: a Redis key holds the single-use right to spend it. The
 *     signature alone cannot express "already used", because anyone holding a
 *     valid signed token could otherwise replay it until it expires.
 *
 * Consumption is a single atomic GETDEL. The check and the invalidation are
 * one round trip, so two concurrent replays of the same token cannot both
 * observe it as unused — which a GET followed by a DEL absolutely would allow.
 */
final class ElevatedActionTokenService
{
    public function __construct(
        private readonly Config $config,
    ) {}

    /**
     * Issue a token bound to this exact action, for this exact user.
     *
     * @param  array<string, mixed>  $parameters
     * @return array{token: string, expires_at: int, expires_in: int, action_hash: string}
     */
    public function issue(User $user, string $action, array $parameters): array
    {
        $ttl = $this->ttl();

        // Carbon rather than time(): the whole clock must be mockable, so that
        // expiry behaviour is provable in tests instead of asserted by waiting.
        $issuedAt = Carbon::now()->getTimestamp();
        $expiresAt = $issuedAt + $ttl;

        $actionHash = ActionHasher::hash($action, $parameters, $user->id);
        $jti = (string) Str::uuid();

        $payload = [
            'jti' => $jti,
            'iss' => (string) $this->config->get('tupay.eat.issuer'),
            'sub' => $user->id,
            'act' => $action,
            'ah' => $actionHash,
            'iat' => $issuedAt,
            'exp' => $expiresAt,
        ];

        $token = $this->sign($payload);

        // NX guards against a jti collision silently overwriting a live token.
        // EX makes Redis, not application code, the authority on the 60s TTL.
        Redis::command('set', [$this->key($jti), $actionHash, 'EX', $ttl, 'NX']);

        return [
            'token' => $token,
            'expires_at' => $expiresAt,
            'expires_in' => $ttl,
            'action_hash' => $actionHash,
        ];
    }

    /**
     * Verify and atomically spend a token against the action actually being
     * requested. Returns the action hash that was authorised.
     *
     * @param  array<string, mixed>  $parameters  the parameters of the REAL request
     *
     * @throws StepUpException
     */
    public function consume(string $token, User $user, string $action, array $parameters): string
    {
        $payload = $this->verifySignature($token);

        if (($payload['iss'] ?? null) !== $this->config->get('tupay.eat.issuer')) {
            throw StepUpException::malformed();
        }

        if ((int) ($payload['sub'] ?? 0) !== $user->id) {
            throw StepUpException::subjectMismatch();
        }

        // Checked before the Redis round trip, so an expired token cannot burn
        // a key that a legitimate retry might still need.
        if ((int) ($payload['exp'] ?? 0) <= Carbon::now()->getTimestamp()) {
            throw StepUpException::expired();
        }

        $jti = (string) ($payload['jti'] ?? '');

        if ($jti === '') {
            throw StepUpException::malformed();
        }

        // The atomic step. GETDEL returns the stored hash and deletes the key
        // in one operation; a second caller racing us gets nil.
        /** @var string|null $storedHash */
        $storedHash = Redis::command('getdel', [$this->key($jti)]);

        if ($storedHash === null || $storedHash === false) {
            throw StepUpException::alreadyConsumed();
        }

        // Recompute from the request we are actually about to execute.
        $expectedHash = ActionHasher::hash($action, $parameters, $user->id);

        // Both comparisons are timing-safe. The signed hash and the stored hash
        // must agree with each other and with the live request; any divergence
        // means the token is being pointed at something it never approved.
        $signedHash = (string) ($payload['ah'] ?? '');

        if (! hash_equals($storedHash, $signedHash) || ! hash_equals($storedHash, $expectedHash)) {
            throw StepUpException::actionMismatch();
        }

        return $storedHash;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sign(array $payload): string
    {
        $encoded = $this->base64UrlEncode(
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );

        $signature = hash_hmac('sha256', $encoded, $this->secret(), true);

        return $encoded.'.'.$this->base64UrlEncode($signature);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws StepUpException
     */
    private function verifySignature(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 2) {
            throw StepUpException::malformed();
        }

        [$encodedPayload, $encodedSignature] = $parts;

        $expected = hash_hmac('sha256', $encodedPayload, $this->secret(), true);
        $provided = $this->base64UrlDecode($encodedSignature);

        if ($provided === false || ! hash_equals($expected, $provided)) {
            throw StepUpException::badSignature();
        }

        $json = $this->base64UrlDecode($encodedPayload);

        if ($json === false) {
            throw StepUpException::malformed();
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw StepUpException::malformed();
        }

        if (! is_array($payload)) {
            throw StepUpException::malformed();
        }

        return $payload;
    }

    private function secret(): string
    {
        $key = (string) $this->config->get('app.key');

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            if ($decoded !== false) {
                $key = $decoded;
            }
        }

        // Domain separation: derive a distinct key so an EAT signature can
        // never be confused with any other artefact signed by APP_KEY.
        return hash_hmac('sha256', 'tupay:eat:v1', $key, true);
    }

    private function key(string $jti): string
    {
        return (string) $this->config->get('tupay.eat.redis_prefix').$jti;
    }

    private function ttl(): int
    {
        return (int) $this->config->get('tupay.eat.ttl', 60);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
