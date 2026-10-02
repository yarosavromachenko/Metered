<?php

declare(strict_types=1);

namespace Metered\Usage\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Quantity\Quantity;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * Never updated; corrections are new events or credit notes. `occurredAt`
 * (client time) decides partition, bucket and invoice; `receivedAt` is when
 * we accepted it. Meter and customer are already resolved to ids.
 */
final readonly class UsageEvent
{
    private function __construct(
        public Uuid $id,
        public TenantContext $tenant,
        public EventId $eventId,
        public Uuid $customerId,
        public Uuid $meterId,
        public Quantity $quantity,
        public DateTimeImmutable $occurredAt,
        public DateTimeImmutable $receivedAt,
        public Properties $properties,
    ) {}

    public static function record(
        Uuid $id,
        TenantContext $tenant,
        EventId $eventId,
        Uuid $customerId,
        Uuid $meterId,
        Quantity $quantity,
        DateTimeImmutable $occurredAt,
        DateTimeImmutable $receivedAt,
        Properties $properties,
    ): self {
        $utc = new DateTimeZone('UTC');

        return new self(
            $id,
            $tenant,
            $eventId,
            $customerId,
            $meterId,
            $quantity,
            // Normalised to UTC: partition key and bucket are derived from it.
            $occurredAt->setTimezone($utc),
            $receivedAt->setTimezone($utc),
            $properties,
        );
    }

    public function bucket(): Bucket
    {
        return Bucket::containing($this->occurredAt);
    }
}
