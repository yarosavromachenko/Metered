<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Ingestion;

use Metered\Shared\Domain\Metering\Aggregation;
use Metered\Usage\Domain\UsageEvent;

/**
 * Event with resolved meter and customer, plus the meter's aggregation so the
 * writer needs no second lookup. The original code and reference are stored
 * too.
 */
final readonly class ResolvedEvent
{
    public function __construct(
        public UsageEvent $event,
        public Aggregation $aggregation,
        public string $meterCode,
        public string $customerReference,
    ) {}
}
