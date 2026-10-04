<?php

declare(strict_types=1);

namespace Metered\Simulation\Infrastructure\Laravel;

use Metered\Shared\Infrastructure\Clock\ClockOffset;
use Metered\Simulation\Application\Port\TimeMachine;

/**
 * Waits just over a second after setting the offset: processes re-read it at
 * most once a second.
 */
final readonly class SharedClockTimeMachine implements TimeMachine
{
    private const int SETTLE_MICROSECONDS = 1_100_000;

    public function __construct(
        private ClockOffset $offset,
        private bool $wait = true,
    ) {}

    public function offsetSeconds(): int
    {
        return $this->offset->seconds();
    }

    public function setOffset(int $seconds): void
    {
        $this->offset->set($seconds);

        if ($this->wait) {
            usleep(self::SETTLE_MICROSECONDS);
        }
    }
}
