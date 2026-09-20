<?php

declare(strict_types=1);

use Metered\Shared\Application\Metrics\Metrics;
use Metered\Shared\Infrastructure\Tracing\TelemetryFlush;
use Metered\Shared\Infrastructure\Tracing\Tracing;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;

/**
 * Octane serves every request from a clone of the booted application, and
 * whatever the clone resolves for the first time is dropped with it. The
 * telemetry has to outlive the request: batching spans and accumulating
 * counters both depend on it.
 */
it('keeps one of each telemetry service across the requests a worker serves', function (string $service): void {
    $booted = app();
    $firstRequest = clone $booted;
    $secondRequest = clone $booted;

    expect($firstRequest->make($service))
        ->toBe($booted->make($service))
        ->toBe($secondRequest->make($service));
})->with([
    TracerProviderInterface::class,
    MeterProviderInterface::class,
    Tracing::class,
    Metrics::class,
    TelemetryFlush::class,
]);
