<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Swap\SwapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/swap
 *
 * Reached only after auth:sanctum AND the elevated:swap middleware, which has
 * already spent a single-use token bound to these exact parameters. By the time
 * this method runs, the request is known to be both authenticated and
 * individually authorised.
 */
final class SwapController extends Controller
{
    public function __construct(
        private readonly SwapService $swaps,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_currency' => ['required', 'string', 'in:NGN,CNY'],
            'target_currency' => ['required', 'string', 'in:NGN,CNY', 'different:source_currency'],
            // Integer subunits only. A decimal string here would be a category
            // error: the API speaks kobo and fen, never naira and yuan.
            'amount_subunits' => ['required', 'integer', 'min:1'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $source = Currency::from($validated['source_currency']);
        $target = Currency::from($validated['target_currency']);
        $amount = Money::ofSubunits((int) $validated['amount_subunits'], $source);

        $transaction = $this->swaps->execute($user, $source, $target, $amount);

        /** @var array<string, mixed> $metadata */
        $metadata = $transaction->metadata;

        return response()->json([
            'transaction_id' => $transaction->id,
            'reference' => $transaction->reference,
            'status' => $transaction->status,
            'source' => [
                'currency' => $metadata['source_currency'] ?? $source->value,
                'gross_subunits' => $metadata['gross_source_subunits'] ?? null,
                'fee_subunits' => $metadata['fee_subunits'] ?? null,
                'net_subunits' => $metadata['net_source_subunits'] ?? null,
            ],
            'target' => [
                'currency' => $metadata['target_currency'] ?? $target->value,
                'credited_subunits' => $metadata['target_subunits'] ?? null,
            ],
            'rate' => $metadata['rate'] ?? null,
            'slippage_bps' => $metadata['slippage_bps'] ?? 0,
            'rate_was_stale' => $metadata['rate_was_stale'] ?? false,
            'balances' => [
                $source->value => $user->walletFor($source)?->balance()->subunits,
                $target->value => $user->walletFor($target)?->balance()->subunits,
            ],
        ]);
    }
}
