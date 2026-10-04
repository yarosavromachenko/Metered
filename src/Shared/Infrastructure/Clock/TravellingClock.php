<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Clock;

use Closure;
use DateInterval;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * Real time plus the offset set by `sim:time-travel`. Bound only in local and
 * demo. The offset is re-read at most once a second.
 */
final class TravellingClock implements ClockInterface
{
    private const int REREAD_NANOSECONDS = 1_000_000_000;

    private ?int $offset = null;

    private int $readAt = 0;

    /**
     * @param  Closure(): int  $monotonic  nanoseconds
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
