<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Metrics;

/**
 * One value of a gauge, with the labels that tell it apart from the gauge's
 * other values.
 */
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
