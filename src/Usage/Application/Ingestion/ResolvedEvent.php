<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Ingestion;

use Metered\Shared\Domain\Metering\Aggregation;
use Metered\Usage\Domain\UsageEvent;

/**
 * An event whose meter and customer turned out to exist, paired with how its
 * meter folds.
 *
 * The aggregation travels with the event because the writer needs it in the
 * same transaction as the insert, and looking it up again there would mean a
 * second round trip to the catalog for something already known.
 */
final readonly class ResolvedEvent
{
    public function __construct(
        public UsageEvent $event,
        public Aggregation $aggregation,
    ) {}
}
