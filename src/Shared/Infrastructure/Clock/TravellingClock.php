<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Clock;

use Closure;
use DateInterval;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * Real time plus the demo's offset: the clock `sim:time-travel` moves, so a
 * reviewer can jump a month ahead and watch the periods close (plan §10).
 *
 * Bound only in the local and demo environments; everywhere else the
 * application has the system clock and nothing to move. The offset is read
 * again at most once a second — every process of the stack picks up a jump
 * within a second of it, and none of them asks the cache on every tick.
 */
final class TravellingClock implements ClockInterface
{
    private const int REREAD_NANOSECONDS = 1_000_000_000;

    private ?int $offset = null;

    private int $readAt = 0;

    /**
     * @param  Closure(): int  $monotonic  nanoseconds from an arbitrary start, for deciding when to read again
     */
    public function __construct(
        private readonly ClockInterface $real,
        private readonly ClockOffset $offsets,
        private readonly Closure $monotonic,
    ) {}

    public function now(): DateTimeImmutable
    {
        $tick = ($this->monotonic)();

        if ($this->offset === null || $tick - $this->readAt >= self::REREAD_NANOSECONDS) {
            $this->offset = $this->offsets->seconds();
            $this->readAt = $tick;
        }

        $now = $this->real->now();

        return $this->offset === 0 ? $now : $now->add(new DateInterval(sprintf('PT%dS', $this->offset)));
    }
}
