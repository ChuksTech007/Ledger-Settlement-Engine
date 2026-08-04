<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Settlement\SettlementStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per settlement, keyed by the provider's reference.
 *
 * @property string $id
 * @property string $provider_reference
 * @property string|null $transaction_id
 * @property int|null $user_id
 * @property string $status
 * @property int $status_rank
 * @property string|null $currency
 * @property int|null $amount
 * @property array<string, mixed> $payload
 * @property list<string> $seen_statuses
 * @property Carbon|null $ledger_posted_at
 */
class SettlementEvent extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider_reference',
        'transaction_id',
        'user_id',
        'status',
        'status_rank',
        'currency',
        'amount',
        'payload',
        'seen_statuses',
        'ledger_posted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'seen_statuses' => 'array',
            'amount' => 'integer',
            'status_rank' => 'integer',
            'ledger_posted_at' => 'datetime',
        ];
    }

    public function status(): SettlementStatus
    {
        return SettlementStatus::from($this->status);
    }

    public function hasSeen(SettlementStatus $status): bool
    {
        return in_array($status->value, $this->seen_statuses ?? [], true);
    }

    public function alreadyPosted(): bool
    {
        return $this->ledger_posted_at !== null;
    }

    /**
     * @return BelongsTo<LedgerTransaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'transaction_id');
    }
}
