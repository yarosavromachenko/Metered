<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Seed;

use DateTimeImmutable;

/**
 * One usage event, as a client would report it.
 */
final readonly class SimulatedEvent
{
    public function __construct(
        public string $eventId,
        public string $meterCode,
        public string $customerRef,
        public string $quantity,
        public DateTimeImmutable $occurredAt,
    ) {}

    /**
     * @return array{event_id: string, meter_code: string, customer_ref: string, quantity: string, occurred_at: string}
     */
    public function toApi(): array
    {
        return [
            'event_id' => $this->eventId,
            'meter_code' => $this->meterCode,
            'customer_ref' => $this->customerRef,
            'quantity' => $this->quantity,
            'occurred_at' => $this->occurredAt->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }
}
