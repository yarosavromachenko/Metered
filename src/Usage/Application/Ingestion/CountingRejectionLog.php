<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Ingestion;

use Metered\Shared\Application\Metrics\Metrics;
use Metered\Usage\Application\Metrics\UsageMetrics;

/**
 * Counts rejections by reason on their way into the log.
 *
 * A decorator, so the log stays about storing rejections for the tenant to
 * read, and the count about telling an operator that, say, a client started
 * sending an unknown meter an hour ago.
 */
final readonly class CountingRejectionLog implements RejectionLog
{
    public function __construct(
        private RejectionLog $log,
        private Metrics $metrics,
    ) {}

    public function record(array $rejections): void
    {
        $this->log->record($rejections);

        $byReason = [];

        foreach ($rejections as $rejection) {
            $byReason[$rejection->reason->value] = ($byReason[$rejection->reason->value] ?? 0) + 1;
        }

        foreach ($byReason as $reason => $count) {
            $this->metrics->add(UsageMetrics::eventsRejected(), $count, ['reason' => $reason]);
        }
    }
}
