<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Clock;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * The only place in the application allowed to ask the operating system what
 * time it is. Everything else receives a ClockInterface.
 *
 * Always UTC, regardless of the host's timezone: a billing period boundary is
 * an instant, not a wall-clock reading, and a machine configured in a different
 * zone must not move it.
 */
final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
