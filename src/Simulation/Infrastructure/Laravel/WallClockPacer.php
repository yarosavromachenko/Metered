<?php

declare(strict_types=1);

namespace Metered\Simulation\Infrastructure\Laravel;

use Metered\Simulation\Application\Port\Pacer;

/**
 * Counts from the first call. A slow second is not made up: the run sends
 * fewer events (shown in the report) instead of drifting.
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
