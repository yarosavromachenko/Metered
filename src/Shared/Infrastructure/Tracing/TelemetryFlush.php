<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Tracing;

use Closure;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface as ExportingTracerProvider;

/**
 * Sends what a long-running process has buffered, at most once a second.
 *
 * PHP has no background thread: the SDK exports a batch only when a span ends
 * after the batch's delay has passed. A process that goes quiet — a relay
 * with nothing to publish, a worker between jobs — would hold its last spans
 * until the next piece of work, and a trace would show its children long
 * before its parent. So every long-running loop calls this at its idle point:
 * after a request is answered, on each pass of a queue worker, on each pass
 * of the daemons.
 */
final class TelemetryFlush
{
    public const int INTERVAL_NANOSECONDS = 1_000_000_000;

    private ?int $lastFlush = null;

    /**
     * @param  Closure(): int  $now  a monotonic clock, in nanoseconds
     */
    public function __construct(
        private readonly TracerProviderInterface $tracerProvider,
        private readonly Closure $now,
    ) {}

    public function flushIfDue(): void
    {
        if (! $this->tracerProvider instanceof ExportingTracerProvider) {
            return;
        }

        $now = ($this->now)();

        if ($this->lastFlush !== null && $now - $this->lastFlush < self::INTERVAL_NANOSECONDS) {
            return;
        }

        $this->lastFlush = $now;
        $this->tracerProvider->forceFlush();
    }
}
