<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Metrics;

/**
 * Where code records what it counted and how long things took, without
 * knowing where the numbers go.
 *
 * Labels are for values from a small, known set — a status, a reason, an
 * endpoint of a tenant's own. Never an id that grows with traffic: every
 * distinct label set is a series of its own.
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
