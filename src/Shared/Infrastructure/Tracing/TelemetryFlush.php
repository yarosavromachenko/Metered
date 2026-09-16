<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Tracing;

use Closure;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface as ExportingMeterProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface as ExportingTracerProvider;

/**
 * Sends what a long-running process has buffered: spans at most once a
 * second, metrics once every ten.
 *
 * PHP has no background thread: the SDK exports a batch of spans only when a
 * span ends after the batch's delay has passed, and collects metrics only
 * when asked. A process that goes quiet — a relay with nothing to publish, a
 * worker between jobs — would hold its last spans until the next piece of
 * work and never report its counters. So every long-running loop calls this
 * at its idle point: after a request is answered, on each pass of a queue
 * worker, on each pass of the daemons.
 */
final class TelemetryFlush
{
    public const int TRACE_INTERVAL_NANOSECONDS = 1_000_000_000;

    public const int METRIC_INTERVAL_NANOSECONDS = 10_000_000_000;

    private ?int $lastTraceFlush = null;

    private ?int $lastMetricFlush = null;

    /**
     * @param  Closure(): int  $now  a monotonic clock, in nanoseconds
     */
    public function __construct(
        private readonly TracerProviderInterface $tracerProvider,
        private readonly MeterProviderInterface $meterProvider,
        private readonly Closure $now,
    ) {}

    public function flushIfDue(): void
    {
        $tracing = $this->tracerProvider instanceof ExportingTracerProvider;
        $metrics = $this->meterProvider instanceof ExportingMeterProvider;

        if (! $tracing && ! $metrics) {
            return;
        }

        $now = ($this->now)();

        if ($tracing && $this->due($this->lastTraceFlush, $now, self::TRACE_INTERVAL_NANOSECONDS)) {
            $this->lastTraceFlush = $now;
            $this->tracerProvider->forceFlush();
        }

        if ($metrics && $this->due($this->lastMetricFlush, $now, self::METRIC_INTERVAL_NANOSECONDS)) {
            $this->lastMetricFlush = $now;
            $this->meterProvider->forceFlush();
        }
    }

    private function due(?int $last, int $now, int $interval): bool
    {
        return $last === null || $now - $last >= $interval;
    }
}
