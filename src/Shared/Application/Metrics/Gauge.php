<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Metrics;

/**
 * A value read as it is at the moment it is asked for: a queue's depth, the
 * age of the oldest unpublished message.
 */
final readonly class Gauge
{
    public function __construct(
        public string $name,
        public string $unit,
        public string $description,
    ) {}
}
