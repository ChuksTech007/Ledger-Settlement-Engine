<?php

declare(strict_types=1);

use App\Domain\Ledger\LedgerException;
use App\Domain\StepUp\StepUpException;
use App\Http\Middleware\RequireElevatedActionToken;
use App\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'elevated' => RequireElevatedActionToken::class,
            'webhook.signature' => VerifyWebhookSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Step-up failures carry their own status: 401 when the token is not a
        // valid credential, 422 when it is valid but does not authorise this
        // request. Rendering centrally keeps that contract in one place.
        $exceptions->render(function (StepUpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error' => $e->errorCode,
                ], $e->status);
            }

            return null;
        });

        // Ledger failures map to 409 Conflict (lock contention, concurrent
        // modification) or 422 (insufficient funds), which is exactly what the
        // concurrency test asserts on.
        $exceptions->render(function (LedgerException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error' => $e->errorCode,
                ], $e->status);
            }

            return null;
        });
    })->create();
