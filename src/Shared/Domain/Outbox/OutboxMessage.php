<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Outbox;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;

/**
 * An integration event, recorded in the same transaction as the state change
 * that produced it.
 *
 * That is the entire point. A transaction and a message queue are two systems
 * with no atomic step between them, so code that commits and then dispatches
 * will, eventually, do one without the other: either an event about a change
 * that rolled back, or a change nobody ever hears about. Writing the message to
 * the same database in the same transaction removes the gap; a relay then
 * publishes it separately.
 *
 * @see \Metered\Shared\Infrastructure\Outbox\OutboxRelay
 */
final readonly class OutboxMessage
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers  carries traceparent, so the eventual
     *                                          delivery belongs to the trace of the
     *                                          request that caused it
     */
    public function __construct(
        public Uuid $id,
        public string $aggregateType,
        public Uuid $aggregateId,
        public string $type,
        public array $payload,
        public array $headers,
        public DateTimeImmutable $occurredAt,
        public int $attempts = 0,
    ) {}
}
