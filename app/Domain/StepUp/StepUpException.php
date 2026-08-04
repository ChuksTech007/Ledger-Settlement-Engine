<?php

declare(strict_types=1);

namespace App\Domain\StepUp;

use RuntimeException;

/**
 * Every way a step-up elevation can fail, with the HTTP status the spec
 * requires for each.
 *
 * The spec permits either 401 or 422 for a replayed token. The split used here
 * is deliberate: 401 means "this token is not (or is no longer) a valid
 * credential", 422 means "the token is valid but does not authorise *this*
 * request". That distinction is what makes a replay attempt debuggable without
 * telling an attacker which half of the check they failed.
 */
final class StepUpException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function missingToken(): self
    {
        return new self(
            'The X-Elevated-Action-Token header is required for this action.',
            'elevated_token_missing',
            401,
        );
    }

    public static function malformed(): self
    {
        return new self(
            'The elevated action token is malformed.',
            'elevated_token_malformed',
            401,
        );
    }

    public static function badSignature(): self
    {
        return new self(
            'The elevated action token signature is invalid.',
            'elevated_token_signature_invalid',
            401,
        );
    }

    public static function expired(): self
    {
        return new self(
            'The elevated action token has expired.',
            'elevated_token_expired',
            401,
        );
    }

    /**
     * Raised when the atomic GETDEL finds nothing: either this token was
     * already spent, or its TTL lapsed. Both are a replay from our side.
     */
    public static function alreadyConsumed(): self
    {
        return new self(
            'The elevated action token has already been used.',
            'elevated_token_replayed',
            401,
        );
    }

    public static function subjectMismatch(): self
    {
        return new self(
            'The elevated action token was issued to a different user.',
            'elevated_token_subject_mismatch',
            401,
        );
    }

    /**
     * The token is authentic and unspent, but the request parameters do not
     * hash to what was approved during the challenge.
     */
    public static function actionMismatch(): self
    {
        return new self(
            'The elevated action token does not authorise these exact parameters.',
            'elevated_token_action_mismatch',
            422,
        );
    }

    public static function invalidTotp(): self
    {
        return new self(
            'The provided TOTP code is invalid.',
            'totp_invalid',
            422,
        );
    }

    public static function totpNotEnrolled(): self
    {
        return new self(
            'This account has no confirmed TOTP enrolment.',
            'totp_not_enrolled',
            422,
        );
    }
}
