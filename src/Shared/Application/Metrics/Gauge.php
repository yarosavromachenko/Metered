<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Metrics;

final readonly class Gauge
{
    public function __construct(
        public string $name,
        public string $unit,
        public string $description,
    ) {}
}
