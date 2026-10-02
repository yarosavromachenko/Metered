<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Tracing;

use Closure;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface as ExportingMeterProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface as ExportingTracerProvider;

/**
 * Called at the idle point of every long-running loop: spans at most once a
 * second, metrics every ten. Without it an idle process would hold its last
 * spans, since PHP has no background export thread.
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
