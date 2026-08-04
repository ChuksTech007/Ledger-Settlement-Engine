<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The journal header. Groups the balanced set of entries that make up one
 * financial event.
 *
 * @property string $id
 * @property string $reference
 * @property string $type
 * @property string $status
 * @property int|null $user_id
 * @property array<string, mixed> $metadata
 * @property-read Collection<int, LedgerEntry> $entries
 */
class LedgerTransaction extends Model
{
    use HasUuids;

    public const TYPE_SWAP = 'swap';

    public const TYPE_SETTLEMENT = 'settlement';

    public const TYPE_FUNDING = 'funding';

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REVERSED = 'reversed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'reference',
        'type',
        'status',
        'user_id',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'transaction_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
