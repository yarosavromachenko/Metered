<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Command;

use DateTimeImmutable;
use Metered\Shared\Domain\Quantity\Quantity;
use Metered\Usage\Domain\EventId;
use Metered\Usage\Domain\Properties;

/**
 * Shape-checked at the endpoint (422 on bad input); meter code and customer
 * reference are resolved later by the consumer (ADR-0003).
 */
final readonly class SubmittedEvent
{
    public function __construct(
        public EventId $eventId,
        public string $meterCode,
        public string $customerReference,
        public Quantity $quantity,
        public DateTimeImmutable $occurredAt,
        public Properties $properties,
    ) {}
}
