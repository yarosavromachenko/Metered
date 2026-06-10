<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Metered\Shared\Presentation\Http\Middleware\EnsureIdempotency;
use Metered\Shared\Presentation\Http\ProblemRenderer;

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
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every API failure is an RFC 9457 problem document, including the ones
        // the framework raises. A client that learns one error shape learns all
        // of them.
        ProblemRenderer::register($exceptions);
    })->create();
