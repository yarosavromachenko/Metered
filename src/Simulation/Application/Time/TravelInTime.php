<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Time;

use DateInterval;
use DateTimeImmutable;

/**
 * Move the demo's clock: forward by an interval, forward to an instant, or
 * back to real time. Nothing given only reports where it stands.
 */
final readonly class TravelInTime
{
    public function __construct(
        public ?DateInterval $by = null,
        public ?DateTimeImmutable $to = null,
        public bool $reset = false,
    ) {}
}
