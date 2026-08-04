<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Stands in for the external rate provider the spec refers to.
 *
 * Kept intentionally trivial and jittered: the caching layer is what is under
 * test, and a rate that never moves would let a broken cache look correct.
 */
final class MockRateController extends Controller
{
    private const BASE_RATE = '0.0048';

    public function __invoke(): JsonResponse
    {
        // +/- 0.5% of jitter, derived with integer arithmetic and applied via
        // bcmath so the payload never contains a float artefact.
        $jitterBps = random_int(-50, 50);
        $factor = bcadd('1', bcdiv((string) $jitterBps, '10000', 18), 18);

        $rate = bcmul(self::BASE_RATE, $factor, 18);

        return response()->json([
            'base' => 'NGN',
            'quote' => 'CNY',
            'rate' => rtrim(rtrim($rate, '0'), '.'),
            'as_of' => now()->toIso8601String(),
        ]);
    }
}
