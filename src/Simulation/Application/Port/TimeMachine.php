<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Port;

/**
 * The offset between the stack's clock and real time. Setting it returns
 * once every process of the stack, this one included, keeps the new time.
 */
interface TimeMachine
{
    public function offsetSeconds(): int;

    public function setOffset(int $seconds): void;
}
