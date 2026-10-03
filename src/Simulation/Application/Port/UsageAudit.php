<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Port;

use DateTimeImmutable;

/**
 * `usage:reconcile` for one project.
 */
interface UsageAudit
{
    public function reconciled(string $projectId, DateTimeImmutable $from, DateTimeImmutable $to): bool;
}
