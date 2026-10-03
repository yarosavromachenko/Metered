<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Time;

use DateInterval;
use DateTimeImmutable;

/**
 * Forward by an interval, to an instant, or reset; with nothing given it
 * reports the current offset.
 */
final readonly class TravelInTime
{
    public function __construct(
        public ?DateInterval $by = null,
        public ?DateTimeImmutable $to = null,
        public bool $reset = false,
    ) {}
}
