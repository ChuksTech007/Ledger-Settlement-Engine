<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the HMAC-SHA256 signature on incoming settlement webhooks.
 *
 * The signature is computed over "{timestamp}.{raw body}" rather than the body
 * alone. Signing the body by itself would let an attacker who captured one
 * valid request replay it verbatim forever; including a timestamp inside the
 * signed material means a replay can be rejected on age without the signature
 * still checking out.
 *
 * The RAW body is used deliberately — re-encoding the decoded JSON would change
 * key order or numeric formatting and break an otherwise valid signature.
 */
final class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('tupay.webhooks.secret');

        if ($secret === '') {
            return response()->json([
                'message' => 'Webhook signature verification is not configured.',
                'error' => 'webhook_secret_missing',
            ], 500);
        }

        $signatureHeader = (string) config('tupay.webhooks.signature_header');
        $timestampHeader = (string) config('tupay.webhooks.timestamp_header');

        $provided = $request->header($signatureHeader);
        $timestamp = $request->header($timestampHeader);

        if (! is_string($provided) || $provided === '' || ! is_string($timestamp) || $timestamp === '') {
            return $this->reject('Missing webhook signature headers.', 'webhook_signature_missing');
        }

        if (! ctype_digit($timestamp)) {
            return $this->reject('Malformed webhook timestamp.', 'webhook_timestamp_invalid');
        }

        $tolerance = (int) config('tupay.webhooks.tolerance', 300);

        if (abs(time() - (int) $timestamp) > $tolerance) {
            return $this->reject('Webhook timestamp outside the accepted tolerance.', 'webhook_timestamp_expired');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        // Strip an optional "sha256=" prefix so both common header styles work.
        $providedDigest = str_starts_with($provided, 'sha256=')
            ? substr($provided, 7)
            : $provided;

        if (! hash_equals($expected, $providedDigest)) {
            return $this->reject('Webhook signature verification failed.', 'webhook_signature_invalid');
        }

        return $next($request);
    }

    private function reject(string $message, string $code): Response
    {
        return response()->json([
            'message' => $message,
            'error' => $code,
        ], 401);
    }
}
