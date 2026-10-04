<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Port;

/**
 * Returns once the given seconds have passed since the first call, by wall
 * clock (not the application clock).
 */
interface Pacer
{
    public function waitUntil(float $secondsSinceStart): void;
}
