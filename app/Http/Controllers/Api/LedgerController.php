<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * GET /api/ledger/{wallet_id}
 *
 * PAGINATION STRATEGY
 * -------------------
 * Keyset (cursor) pagination, not OFFSET. A ledger is append-only and grows
 * without bound, and OFFSET forces the database to walk and discard every
 * skipped row — page 10,000 costs proportionally more than page 1. Keyset
 * pagination seeks directly into the index and costs the same at any depth.
 *
 * It is also correct under concurrent writes: OFFSET shifts rows between pages
 * whenever a new entry is inserted mid-traversal, so a client paging through a
 * live ledger can see the same entry twice or miss one entirely.
 *
 * The composite index (wallet_id, id) serves this exactly: equality on the
 * leading column, range scan on the second, returned in index order with no
 * sort step.
 */
final class LedgerController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    public function __invoke(Request $request, string $walletId): JsonResponse
    {
        $wallet = Wallet::query()->findOrFail($walletId);

        /** @var User $user */
        $user = $request->user();

        // A ledger is not public. Ownership is checked here rather than left to
        // an obscure wallet id, because an unguessable identifier is not an
        // authorisation control.
        if ($wallet->user_id !== $user->id) {
            throw new AccessDeniedHttpException('This wallet does not belong to you.');
        }

        $perPage = min(
            max((int) $request->integer('per_page', self::DEFAULT_PER_PAGE), 1),
            self::MAX_PER_PAGE,
        );

        $query = LedgerEntry::query()
            ->where('wallet_id', $wallet->id)
            ->orderByDesc('id')
            ->limit($perPage + 1); // one extra row tells us whether more exist

        // Cursor: entries strictly older than the last id the client saw.
        $cursor = $request->integer('cursor', 0);

        if ($cursor > 0) {
            $query->where('id', '<', $cursor);
        }

        $entries = $query->get();

        $hasMore = $entries->count() > $perPage;
        $page = $hasMore ? $entries->take($perPage) : $entries;

        return response()->json([
            'wallet' => [
                'id' => $wallet->id,
                'currency' => $wallet->currency,
                'type' => $wallet->type,
                // Derived, never stored.
                'balance_subunits' => $wallet->balance()->subunits,
            ],
            'data' => $page->map(fn (LedgerEntry $entry): array => [
                'id' => $entry->id,
                'transaction_id' => $entry->transaction_id,
                'direction' => $entry->direction,
                'amount_subunits' => $entry->amount,
                'signed_amount_subunits' => $entry->signed_amount,
                'currency' => $entry->currency,
                'created_at' => $entry->created_at?->toIso8601String(),
            ])->values(),
            'pagination' => [
                'per_page' => $perPage,
                'has_more' => $hasMore,
                'next_cursor' => $hasMore ? $page->last()?->id : null,
            ],
        ]);
    }
}
