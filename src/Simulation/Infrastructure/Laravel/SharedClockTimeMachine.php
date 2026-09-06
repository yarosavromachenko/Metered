<?php

declare(strict_types=1);

namespace Metered\Simulation\Infrastructure\Laravel;

use Metered\Shared\Infrastructure\Clock\ClockOffset;
use Metered\Simulation\Application\Port\TimeMachine;

/**
 * The offset the travelling clock reads. Each process reads it again at most
 * once a second, so after setting it this waits a little over a second: by
 * the time it returns, the app, the workers and this very process all agree
 * on the new time.
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
