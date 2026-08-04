<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Money\Currency;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string|null $totp_secret
 * @property Carbon|null $totp_confirmed_at
 * @property-read Collection<int, Wallet> $wallets
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'email_verified_at',
        'password',
        'totp_secret',
        'totp_confirmed_at',
    ];

    /**
     * The TOTP seed is a bearer credential in its own right: anyone holding it
     * can mint valid codes forever. It never leaves the server.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'totp_secret',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'totp_confirmed_at' => 'datetime',
            'password' => 'hashed',
            // Encrypted at rest, so a leaked database dump does not hand over
            // every user's second factor.
            'totp_secret' => 'encrypted',
        ];
    }

    /**
     * @return HasMany<Wallet, $this>
     */
    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    public function walletFor(Currency $currency): ?Wallet
    {
        return $this->wallets()->where('currency', $currency->value)->first();
    }

    public function hasTotpEnabled(): bool
    {
        return $this->totp_secret !== null && $this->totp_confirmed_at !== null;
    }
}
