<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Metrics;

/**
 * The shared kernel's gauges, named once (docs/observability.md).
 */
final class SharedMetrics
{
    public static function outboxAge(): Gauge
    {
        return new Gauge('outbox.unpublished.age', 's', 'Age of the oldest outbox message not yet published; 0 when none waits');
    }

    public static function queueDepth(): Gauge
    {
        return new Gauge('queue.depth', '{job}', 'Jobs waiting on a queue, by queue');
    }
}
