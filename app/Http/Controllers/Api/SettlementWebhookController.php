<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Settlement\SettlementStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessSettlement;
use App\Models\SettlementEvent;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/webhooks/settlement
 *
 * The signature has already been verified by middleware. This handler's job is
 * to decide, quickly and idempotently, whether the event represents new
 * information — and if so, to hand the expensive work to a queue.
 *
 * THREE HAZARDS, THREE DEFENCES
 *
 *   Duplicate delivery  -> a UNIQUE index on provider_reference. Redis could
 *                          front this, but only the database can make
 *                          "exactly once" survive a cache flush, so the
 *                          constraint is the authority and the race is
 *                          resolved by catching its violation rather than by
 *                          a check-then-insert that has a window between the
 *                          two steps.
 *
 *   Out-of-order        -> ranked state machine. A late INITIATED arriving
 *                          after COMPLETED is recorded as seen but does not
 *                          move the settlement backwards.
 *
 *   Slow work           -> the response returns as soon as the event is
 *                          durably recorded; ledger posting happens on a queue.
 */
final class SettlementWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider_reference' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', 'in:INITIATED,PROCESSING,COMPLETED,FAILED'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'currency' => ['required', 'string', 'in:NGN,CNY'],
            'amount_subunits' => ['required', 'integer', 'min:1'],
        ]);

        $status = SettlementStatus::from($validated['status']);
        $reference = $validated['provider_reference'];

        [$event, $outcome] = $this->recordEvent($reference, $status, $validated, $request->all());

        // Only a first-time COMPLETED moves money.
        if ($outcome === 'applied' && $status->shouldPostToLedger() && ! $event->alreadyPosted()) {
            ProcessSettlement::dispatch($event->id);
        }

        return response()->json([
            'provider_reference' => $reference,
            'status' => $event->status,
            'outcome' => $outcome,
            'queued' => $outcome === 'applied' && $status->shouldPostToLedger(),
        ], $outcome === 'duplicate' ? 200 : 202);
    }

    /**
     * Insert or advance the settlement row.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $rawPayload
     * @return array{0: SettlementEvent, 1: string}
     */
    private function recordEvent(string $reference, SettlementStatus $status, array $validated, array $rawPayload): array
    {
        try {
            // Optimistic insert. If two deliveries race, exactly one wins and
            // the other lands in the catch below — there is no window between
            // a check and the write for a duplicate to slip through.
            //
            // Wrapped in its own transaction (a SAVEPOINT when nested) because
            // PostgreSQL aborts the WHOLE transaction on a constraint
            // violation: without this, the expected unique-violation would
            // poison the connection and every subsequent statement would fail
            // with "current transaction is aborted". Rolling back to a
            // savepoint confines the failure to the statement that caused it.
            $event = DB::transaction(fn (): SettlementEvent => SettlementEvent::create([
                'provider_reference' => $reference,
                'user_id' => $validated['user_id'],
                'status' => $status->value,
                'status_rank' => $status->rank(),
                'currency' => $validated['currency'],
                'amount' => $validated['amount_subunits'],
                'payload' => $rawPayload,
                'seen_statuses' => [$status->value],
            ]), attempts: 1);

            return [$event, 'applied'];
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }
        }

        // Already known. Decide whether this delivery advances it.
        return DB::transaction(function () use ($reference, $status): array {
            /** @var SettlementEvent $event */
            $event = SettlementEvent::query()
                ->where('provider_reference', $reference)
                ->lockForUpdate()
                ->firstOrFail();

            if ($event->hasSeen($status)) {
                return [$event, 'duplicate'];
            }

            $seen = $event->seen_statuses;
            $seen[] = $status->value;
            $event->seen_statuses = $seen;

            // The out-of-order guard: only a strictly higher rank advances the
            // settlement. A late INITIATED after COMPLETED is recorded as seen
            // but leaves status untouched.
            if ($event->status()->supersededBy($status)) {
                $event->status = $status->value;
                $event->status_rank = $status->rank();
                $event->save();

                return [$event, 'applied'];
            }

            $event->save();

            return [$event, 'ignored_out_of_order'];
        });
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // 23505 unique_violation
        return (string) ($e->errorInfo[0] ?? '') === '23505';
    }
}
