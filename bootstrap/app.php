<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Metered\Shared\Presentation\Http\Middleware\EnsureIdempotency;
use Metered\Shared\Presentation\Http\Middleware\TraceRequest;
use Metered\Shared\Presentation\Http\ProblemRenderer;
use Metered\Tenancy\Presentation\Http\Middleware\AuthenticateApiKey;
use Metered\Tenancy\Presentation\Http\Middleware\ThrottleApiKey;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'idempotent' => EnsureIdempotency::class,
            // Routes name the scope they need: `api-key:usage:write`.
            'api-key' => AuthenticateApiKey::class,
            'throttle-api-key' => ThrottleApiKey::class,
        ]);

        // Outermost on the API stack: a span that does not cover the
        // middleware below it measures the wrong thing.
        $middleware->prependToGroup('api', TraceRequest::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every API failure is an RFC 9457 problem document, including the ones
        // the framework raises. A client that learns one error shape learns all
        // of them.
        ProblemRenderer::register($exceptions);
    })->create();
