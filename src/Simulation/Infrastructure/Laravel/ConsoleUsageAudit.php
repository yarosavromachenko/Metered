<?php

declare(strict_types=1);

namespace Metered\Simulation\Infrastructure\Laravel;

use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Metered\Simulation\Application\Port\UsageAudit;

final readonly class ConsoleUsageAudit implements UsageAudit
{
    public function __construct(private Kernel $console) {}

    public function reconciled(string $projectId, DateTimeImmutable $from, DateTimeImmutable $to): bool
    {
        return $this->console->call('usage:reconcile', [
            '--project' => $projectId,
            '--from' => $from->format(DATE_ATOM),
            '--to' => $to->format(DATE_ATOM),
        ]) === 0;
    }
}
