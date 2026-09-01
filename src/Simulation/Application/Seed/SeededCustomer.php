<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Seed;

use DateTimeImmutable;

/**
 * One customer of the seeded tenant, and what happens to them.
 *
 * `size` scales how much they use: 1.0 is the profile's average customer.
 * `switchesTo` is a plan they move to at the end of a period; `cancels` ends
 * their subscription at the end of the current one.
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
