<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use PragmaRX\Google2FA\Google2FA;

/**
 * Convenience for manual API testing.
 *
 * The step-up flow needs a live TOTP code, which rotates every 30 seconds and
 * cannot be hard-coded into an .http file or a Postman collection. This prints
 * the current one so a reviewer can exercise the flow without installing an
 * authenticator app.
 */
final class ShowTotpCode extends Command
{
    protected $signature = 'tupay:totp {email=trader@tupay.test}';

    protected $description = 'Print the current TOTP code for a seeded test user';

    public function handle(Google2FA $google2fa): int
    {
        $email = (string) $this->argument('email');

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No user found with email {$email}. Run `php artisan db:seed` first.");

            return self::FAILURE;
        }

        if (! $user->hasTotpEnabled()) {
            $this->error("User {$email} has no confirmed TOTP enrolment.");

            return self::FAILURE;
        }

        $code = $google2fa->getCurrentOtp((string) $user->totp_secret);

        // Seconds remaining in the current 30-second window, so the reviewer
        // knows whether to use this code or wait for the next one.
        $secondsLeft = 30 - (time() % 30);

        $this->newLine();
        $this->info("TOTP code for {$email}: {$code}");
        $this->line("Valid for another {$secondsLeft}s");
        $this->newLine();

        return self::SUCCESS;
    }
}
