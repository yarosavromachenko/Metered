<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Port;

/**
 * Setting the offset returns once every process sees the new time.
 */
interface TimeMachine
{
    public function offsetSeconds(): int;

    public function setOffset(int $seconds): void;
}
