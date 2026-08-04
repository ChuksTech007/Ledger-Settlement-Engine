<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A wallet deliberately carries NO balance column.
 *
 * Every balance in this system is derived from ledger_entries. That is the
 * whole point of the design: there is exactly one source of truth, and it is
 * the append-only entry log. A cached balance column would be a second source
 * of truth and therefore a bug waiting for a race condition.
 *
 * @property string $id
 * @property int|null $user_id
 * @property string $currency
 * @property string $type
 * @property-read Collection<int, LedgerEntry> $entries
 */
class Wallet extends Model
{
    // No factory: wallets are always created through a funding posting or the
    // seeder, never fabricated, so that a wallet can never exist with a balance
    // that no ledger entry accounts for.
    use HasUuids;

    public const TYPE_USER = 'user';

    public const TYPE_SYSTEM = 'system';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'currency',
        'type',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function currency(): Currency
    {
        return Currency::from($this->currency);
    }

    public function isSystem(): bool
    {
        return $this->type === self::TYPE_SYSTEM;
    }

    /**
     * Derived balance: an indexed SUM over the generated signed_amount column.
     *
     * Served by ledger_entries_wallet_id_id_index as an index-only scan, so
     * this stays cheap without a denormalised column.
     *
     * NOTE: outside of a row-locked transaction this is a point-in-time read
     * and may be stale the instant it returns. The swap engine always calls
     * lockedBalance() instead.
     */
    public function balance(): Money
    {
        /** @var int|string|null $sum */
        $sum = $this->entries()->sum('signed_amount');

        return Money::fromNumericString((string) ($sum ?? 0), $this->currency());
    }

    /**
     * Balance read under a pessimistic row lock.
     *
     * The lock is taken on the WALLET row, not on the entries. Two reasons:
     *
     *   1. PostgreSQL rejects FOR UPDATE combined with an aggregate, so
     *      `lockForUpdate()->sum()` is not merely inefficient, it is invalid.
     *   2. Locking existing entry rows would not block anyway — the danger is
     *      a concurrent INSERT of a *new* entry, which no lock on already
     *      visible rows can prevent.
     *
     * The wallet row is therefore the serialisation point: every swap touching
     * this wallet must acquire it, so concurrent swaps queue behind one another
     * instead of all reading the same pre-debit balance and each concluding it
     * can afford the transfer.
     */
    public function lockedBalance(): Money
    {
        static::query()->whereKey($this->getKey())->lockForUpdate()->get();

        return $this->balance();
    }
}
