<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Ingestion;

use Metered\Shared\Application\Metrics\Metrics;
use Metered\Usage\Application\Metrics\UsageMetrics;

/**
 * Decorator: counts rejections by reason.
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
