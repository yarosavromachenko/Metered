<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Port;

use DateTimeImmutable;

/**
 * `usage:reconcile` over one project's window: do the aggregates agree with
 * the events under them?
 */
interface UsageAudit
{
    public function reconciled(string $projectId, DateTimeImmutable $from, DateTimeImmutable $to): bool;
}
