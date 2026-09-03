<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Seed;

final readonly class SeedReport
{
    public function __construct(
        public ?string $organizationSlug,
        public ?string $token,
        public int $meters,
        public int $plans,
        public int $endpoints,
        public int $customers,
        public int $eventsSent,
        public int $eventsAccepted,
        public int $duplicatesSent,
        public int $rejectsSent,
    ) {}
}
