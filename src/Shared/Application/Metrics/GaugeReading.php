<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Metrics;

final readonly class GaugeReading
{
    /**
     * @param  array<non-empty-string, string>  $labels
     */
    public function __construct(
        public Gauge $gauge,
        public int $value,
        public array $labels = [],
    ) {}
}
