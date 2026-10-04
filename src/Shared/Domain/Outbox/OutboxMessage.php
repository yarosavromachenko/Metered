<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Outbox;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;

/**
 * Integration event written in the same transaction as the change that caused
 * it; the relay publishes it afterwards.
 *
 * @see \Metered\Shared\Infrastructure\Outbox\OutboxRelay
 */
final readonly class OutboxMessage
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers  includes traceparent
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
