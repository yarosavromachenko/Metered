<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Time;

use DateTimeImmutable;

final readonly class Travelled
{
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public int $offsetSeconds,
    ) {}
}
