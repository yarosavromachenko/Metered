<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Metrics;

use Metered\Shared\Application\Metrics\Counter;
use Metered\Shared\Application\Metrics\Gauge;
use Metered\Shared\Application\Metrics\Histogram;
use Metered\Shared\Application\Metrics\Scale;

/**
 * The ingestion path's instruments, named once (docs/observability.md).
 */
final class UsageMetrics
{
    public static function ingestRequests(): Counter
    {
        return new Counter('ingest.requests', '{request}', 'Ingestion requests answered, by status');
    }

    public static function ingestDuration(): Histogram
    {
        return Histogram::duration('ingest.duration', 'Time to answer an ingestion request, by status');
    }

    public static function eventsRejected(): Counter
    {
        return new Counter('usage.events.rejected', '{event}', 'Events the consumer refused, by reason');
    }

    public static function batchWriteDuration(): Histogram
    {
        return Histogram::duration('usage.batch_write.duration', 'Time to write one tenant\'s share of a batch to PostgreSQL');
    }

    public static function batchSize(): Histogram
    {
        return new Histogram('usage.batch.size', '{event}', 'Events in one tenant\'s share of a batch', Scale::Count);
    }

    public static function streamLength(): Gauge
    {
        return new Gauge('usage.stream.length', '{message}', 'Entries in the ingestion stream, read or not, until trimmed');
    }

    public static function streamPending(): Gauge
    {
        return new Gauge('usage.stream.pending', '{message}', 'Entries accepted and not yet written: the backpressure depth');
    }

    public static function deadLetters(): Gauge
    {
        return new Gauge('dlq.size', '{message}', 'Entries in the ingestion dead-letter stream');
    }
}
