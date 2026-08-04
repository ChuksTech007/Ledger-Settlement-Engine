<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single leg of a double-entry posting. Append-only: the database rejects
 * UPDATE and DELETE outright, so history cannot be rewritten.
 *
 * @property int $id
 * @property string $transaction_id
 * @property string $wallet_id
 * @property string $direction
 * @property int $amount
 * @property string $currency
 * @property int $signed_amount
 */
class LedgerEntry extends Model
{
    public const DIRECTION_DEBIT = 'debit';

    public const DIRECTION_CREDIT = 'credit';

    /**
     * signed_amount is intentionally absent: it is a database-generated column
     * and any attempt to write it would be rejected by PostgreSQL.
     *
     * @var list<string>
     */
    protected $fillable = [
        'transaction_id',
        'wallet_id',
        'direction',
        'amount',
        'currency',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'signed_amount' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Wallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * @return BelongsTo<LedgerTransaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'transaction_id');
    }

    public function money(): Money
    {
        return Money::ofSubunits($this->amount, Currency::from($this->currency));
    }

    public function isDebit(): bool
    {
        return $this->direction === self::DIRECTION_DEBIT;
    }
}
