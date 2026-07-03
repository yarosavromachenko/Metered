<?php

declare(strict_types=1);

namespace Metered\Usage\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Quantity\Quantity;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * One fact of consumption, as it will be stored.
 *
 * Immutable, and not merely by convention: an event is something that already
 * happened. Correcting one is a second event or a credit note, never an
 * update — which is also why the table takes no UPDATE path at all.
 *
 * It carries two instants. `occurredAt` is the client's word about when the
 * thing happened, and it decides the partition, the bucket and the invoice
 * the usage lands on. `receivedAt` is ours, and it is what a reconciliation
 * or a late-event investigation measures the gap against.
 *
 * By this point the meter and the customer are ids, not the code and
 * reference the client sent: resolving them is the consumer's job, and an
 * event that reaches this constructor is one that named something real.
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
            // Stored in UTC whatever offset the client wrote it in: the
            // partition key, the bucket and every comparison downstream read
            // this column, and an offset in it would make two of the same
            // instant.
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
