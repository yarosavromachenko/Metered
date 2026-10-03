<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Seed;

use DateTimeImmutable;

/**
 * `size` 1.0 is the profile average. `switchesTo` and `cancels` take effect
 * at the end of a period.
 */
final readonly class SeededCustomer
{
    public function __construct(
        public string $reference,
        public string $name,
        public string $plan,
        public DateTimeImmutable $startsAt,
        public float $size,
        public bool $silent = false,
        public ?string $switchesTo = null,
        public bool $cancels = false,
    ) {}
}
