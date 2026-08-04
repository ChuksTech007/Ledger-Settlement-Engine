<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use RuntimeException;

/**
 * Failures raised by the ledger and swap engine.
 *
 * The status codes here are load-bearing: the mandated concurrency test asserts
 * that of 10 simultaneous swaps against a balance sufficient for one, exactly
 * one returns 200 and the other nine return 409 or 422. Every losing path below
 * therefore resolves to one of those two.
 *
 *   409 Conflict          -> the request lost a race (lock contention, or the
 *                            database aborted us to preserve isolation)
 *   422 Unprocessable     -> the request was well-formed but cannot be
 *                            satisfied against the current balance
 */
final class LedgerException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    /**
     * Another request currently holds the Redis lock on this user or wallet.
     * We fail fast rather than queue: a caller that waits would still have to
     * re-validate the balance afterwards, and blocking turns a race into a
     * latency spike.
     */
    public static function lockContention(): self
    {
        return new self(
            'Another operation is currently modifying this wallet. Please retry.',
            'wallet_locked',
            409,
        );
    }

    /**
     * PostgreSQL aborted the transaction to preserve REPEATABLE READ isolation
     * (SQLSTATE 40001/40P01). Semantically identical to losing the lock race.
     */
    public static function serializationFailure(): self
    {
        return new self(
            'The transaction conflicted with a concurrent operation. Please retry.',
            'serialization_failure',
            409,
        );
    }

    public static function insufficientFunds(string $detail = ''): self
    {
        return new self(
            $detail !== '' ? $detail : 'Insufficient funds for this swap.',
            'insufficient_funds',
            422,
        );
    }

    public static function walletNotFound(string $currency): self
    {
        return new self(
            "No {$currency} wallet exists for this user.",
            'wallet_not_found',
            422,
        );
    }

    public static function liquidityUnavailable(string $currency): self
    {
        return new self(
            "The {$currency} liquidity pool cannot cover this swap.",
            'liquidity_unavailable',
            422,
        );
    }

    public static function rateUnavailable(): self
    {
        return new self(
            'A current exchange rate could not be obtained.',
            'rate_unavailable',
            503,
        );
    }

    public static function amountTooSmall(): self
    {
        return new self(
            'The converted amount rounds to zero subunits.',
            'amount_too_small',
            422,
        );
    }

    public static function duplicateRequest(): self
    {
        return new self(
            'This request has already been processed.',
            'duplicate_request',
            409,
        );
    }
}
