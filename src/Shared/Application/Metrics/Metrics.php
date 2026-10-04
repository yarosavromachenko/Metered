<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Metrics;

/**
 * Label values come from a small fixed set (status, reason). No ids: each
 * label set is a separate series.
 */
interface Metrics
{
    /**
     * @param  array<non-empty-string, string>  $labels
     */
    public function add(Counter $counter, int $increment = 1, array $labels = []): void;

    /**
     * @param  int  $value  in the histogram's scale: nanoseconds for a duration
     * @param  array<non-empty-string, string>  $labels
     */
    public function record(Histogram $histogram, int $value, array $labels = []): void;
}
