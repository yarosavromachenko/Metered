<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Port;

final readonly class LoadedHistory
{
    public function __construct(
        public int $events,
        public int $aggregates,
        public bool $reconciled,
    ) {}
}
