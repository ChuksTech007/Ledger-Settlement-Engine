<?php

declare(strict_types=1);

namespace App\Services\StepUp;

use App\Domain\StepUp\StepUpException;
use App\Models\User;
use Illuminate\Support\Facades\Redis;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP verification with replay protection.
 *
 * A TOTP code stays valid for its whole time window (plus the drift window),
 * which means the same six digits can be presented several times within ~90
 * seconds. Since a valid code is what mints an EAT, an attacker who observes
 * one code could otherwise mint several elevated tokens from it. Each accepted
 * code is therefore burned in Redis for the duration of its validity window.
 */
final class TotpService
{
    private const USED_CODE_PREFIX = 'totp:used:';

    /** Accept one window either side to tolerate clock drift. */
    private const DRIFT_WINDOWS = 1;

    public function __construct(
        private readonly Google2FA $google2fa,
    ) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    /**
     * @throws StepUpException
     */
    public function verify(User $user, string $code): void
    {
        if (! $user->hasTotpEnabled()) {
            throw StepUpException::totpNotEnrolled();
        }

        $secret = (string) $user->totp_secret;

        // Normalise: authenticator apps often display "123 456".
        $code = preg_replace('/\s+/', '', $code) ?? $code;

        $isValid = $this->google2fa->verifyKey($secret, $code, self::DRIFT_WINDOWS);

        if ($isValid === false) {
            throw StepUpException::invalidTotp();
        }

        $this->burn($user, $code);
    }

    /**
     * Mark a code as spent. SET NX is atomic, so two concurrent requests
     * presenting the same code cannot both succeed.
     *
     * @throws StepUpException
     */
    private function burn(User $user, string $code): void
    {
        $key = self::USED_CODE_PREFIX.$user->id.':'.hash('sha256', $code);

        // Cover the current window plus drift on both sides.
        $ttl = 30 * (1 + 2 * self::DRIFT_WINDOWS);

        $stored = Redis::command('set', [$key, '1', 'EX', $ttl, 'NX']);

        // predis returns null when NX declines; phpredis returns false.
        if ($stored === null || $stored === false) {
            throw StepUpException::invalidTotp();
        }
    }

    public function provisioningUri(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl('Tupay', $user->email, $secret);
    }
}
