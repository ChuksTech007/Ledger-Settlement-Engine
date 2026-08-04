<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Money\Currency;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'totp_secret' => null,
            'totp_confirmed_at' => null,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Enrol the user in TOTP with a real, verifiable secret.
     *
     * Tests need to generate genuinely valid codes, so this must be an actual
     * base32 seed rather than a random string.
     */
    public function withTotp(?string $secret = null): static
    {
        $secret ??= (new Google2FA)->generateSecretKey(32);

        return $this->state(fn (array $attributes) => [
            'totp_secret' => $secret,
            'totp_confirmed_at' => now(),
        ]);
    }

    /**
     * Give the user an NGN and a CNY wallet once created.
     */
    public function withWallets(): static
    {
        return $this->afterCreating(function ($user): void {
            foreach (Currency::cases() as $currency) {
                Wallet::query()->firstOrCreate([
                    'user_id' => $user->id,
                    'currency' => $currency->value,
                ], [
                    'type' => Wallet::TYPE_USER,
                ]);
            }
        });
    }
}
