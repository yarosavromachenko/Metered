<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Port;

/**
 * Keeps generated traffic to its schedule: returns once the given number of
 * seconds has passed since the first call. Wall-clock time, not the
 * application's clock — pacing is about how fast requests leave this
 * process, whatever time the platform believes it is.
 */
interface Pacer
{
    public function waitUntil(float $secondsSinceStart): void;
}
