<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Command;

use DateTimeImmutable;
use Metered\Shared\Domain\Quantity\Quantity;
use Metered\Usage\Domain\EventId;
use Metered\Usage\Domain\Properties;

/**
 * One event as a client sent it, after the endpoint has checked its shape and
 * before anything has checked whether it refers to real things.
 *
 * The meter and the customer are still the strings the client used: resolving
 * them costs a database round trip, and the hot path does not make one
 * (ADR-0003). What the edge does check is everything it can check without
 * leaving the process — that the quantity is a number it can store, that the
 * timestamp is a timestamp, that the labels are labels — because a client
 * which sent nonsense deserves an immediate 422 rather than a rejection they
 * have to go looking for later.
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
