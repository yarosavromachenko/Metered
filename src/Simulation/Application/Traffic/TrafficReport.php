<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Traffic;

final readonly class TrafficReport
{
    public function __construct(
        public int $sent,
        public int $accepted,
        public int $duplicates,
        public int $late,
        public int $requests,
    ) {}
}
