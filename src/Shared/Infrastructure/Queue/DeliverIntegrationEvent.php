<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Queue;

use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Outbox\OutboxMessage;
use Metered\Shared\Infrastructure\Inbox\IntegrationEventDispatcher;

/**
 * Carries one published integration event to its handlers.
 *
 * The message travels as plain scalars rather than as a serialised object, so
 * a job queued by one deployment is still readable by the next one.
 */
final class DeliverIntegrationEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $id,
        public readonly string $aggregateType,
        public readonly string $aggregateId,
        public readonly string $type,
        public readonly array $payload,
        public readonly array $headers,
        public readonly string $occurredAt,
    ) {}

    public static function fromMessage(OutboxMessage $message): self
    {
        return new self(
            id: $message->id->value,
            aggregateType: $message->aggregateType,
            aggregateId: $message->aggregateId->value,
            type: $message->type,
            payload: $message->payload,
            headers: $message->headers,
            occurredAt: $message->occurredAt->format(DateTimeImmutable::RFC3339_EXTENDED),
        );
    }

    public function handle(IntegrationEventDispatcher $dispatcher): void
    {
        $dispatcher->dispatch(new OutboxMessage(
            id: Uuid::fromString($this->id),
            aggregateType: $this->aggregateType,
            aggregateId: Uuid::fromString($this->aggregateId),
            type: $this->type,
            payload: $this->payload,
            headers: $this->headers,
            occurredAt: new DateTimeImmutable($this->occurredAt),
        ));
    }
}
