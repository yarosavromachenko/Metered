<?php

declare(strict_types=1);

namespace Metered\Simulation\Infrastructure\Laravel;

use Metered\Simulation\Application\Port\Pacer;

/**
 * Sleeps until the schedule catches up, counting from its first call. When a
 * second's requests took longer than a second, the next second starts at
 * once rather than drifting: the run then sends less than it was asked to,
 * which the report shows, instead of stretching out.
 */
final class WallClockPacer implements Pacer
{
    private ?int $start = null;

    public function waitUntil(float $secondsSinceStart): void
    {
        $this->start ??= (int) hrtime(true);
        $wait = (int) ($this->start + $secondsSinceStart * 1e9 - hrtime(true));

        if ($wait > 0) {
            usleep(intdiv($wait, 1000));
        }
    }
}
