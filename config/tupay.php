<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Elevated Action Token (step-up 2FA)
    |--------------------------------------------------------------------------
    |
    | An EAT is minted only after a valid TOTP code and is bound to a SHA-256
    | hash of the exact action it authorises. It is single-use: consumption is
    | an atomic Redis GETDEL, so a replay inside the TTL finds nothing.
    |
    */
    'eat' => [
        'ttl' => (int) env('TUPAY_EAT_TTL', 60),
        'issuer' => (string) env('TUPAY_EAT_ISSUER', 'tupay-step-up'),
        'redis_prefix' => 'eat:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Distributed locking
    |--------------------------------------------------------------------------
    |
    | Lock TTL must exceed the worst-case swap duration, but stay short enough
    | that a process that dies mid-swap cannot wedge a wallet indefinitely.
    | block_seconds = 0 means we fail fast with 409 rather than queueing, which
    | is what makes the concurrency test deterministic.
    |
    */
    'lock' => [
        'ttl' => (int) env('TUPAY_LOCK_TTL', 10),
        'block_seconds' => (int) env('TUPAY_LOCK_BLOCK_SECONDS', 0),
        'prefix' => 'lock:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate engine (stale-while-revalidate)
    |--------------------------------------------------------------------------
    |
    | Within `fresh_ttl` the cached rate is served directly. Between fresh_ttl
    | and stale_ttl the stale rate is still served, but a background refresh is
    | dispatched. Past stale_ttl we must fetch synchronously. This keeps swap
    | latency flat and shields the provider from a thundering herd.
    |
    */
    'rates' => [
        'endpoint' => (string) env('TUPAY_RATE_ENDPOINT', 'http://127.0.0.1:8000/api/mock/rates'),
        'fresh_ttl' => (int) env('TUPAY_RATE_FRESH_TTL', 30),
        'stale_ttl' => (int) env('TUPAY_RATE_STALE_TTL', 300),
        'cache_key' => 'rates:ngn_cny',
        'refresh_lock_key' => 'rates:ngn_cny:refreshing',
        'timeout' => 3,
        'scale' => 18,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tiered dynamic slippage
    |--------------------------------------------------------------------------
    |
    | Swaps above the threshold incur a progressive spread:
    |   0.5% base, plus 0.1% for every additional 500,000 NGN above it.
    |
    | Everything is expressed in kobo and basis points so the calculation never
    | leaves integer arithmetic. 50 bps = 0.5%, 10 bps = 0.1%.
    |
    */
    'slippage' => [
        'threshold_subunits' => (int) env('TUPAY_SLIPPAGE_THRESHOLD_KOBO', 100_000_000),
        'base_bps' => (int) env('TUPAY_SLIPPAGE_BASE_BPS', 50),
        'step_subunits' => (int) env('TUPAY_SLIPPAGE_STEP_KOBO', 50_000_000),
        'step_bps' => (int) env('TUPAY_SLIPPAGE_STEP_BPS', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Settlement webhooks
    |--------------------------------------------------------------------------
    */
    'webhooks' => [
        'secret' => (string) env('TUPAY_WEBHOOK_SECRET', ''),
        'tolerance' => (int) env('TUPAY_WEBHOOK_TOLERANCE', 300),
        'signature_header' => 'X-Tupay-Signature',
        'timestamp_header' => 'X-Tupay-Timestamp',
    ],

];
