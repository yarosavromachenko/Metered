<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Outbox\OutboxMessage;
use Metered\Shared\Infrastructure\Outbox\QueueOutboxPublisher;
use Metered\Shared\Infrastructure\Queue\DeliverIntegrationEvent;

it('puts the message on the queue with its payload and headers intact', function (): void {
    Bus::fake();

    $id = Uuid::fromString('01932b1c-4f00-7000-8000-0123456789ab');
    $aggregateId = Uuid::fromString('01932b1c-4f00-7000-8000-0123456789ac');

    new QueueOutboxPublisher(app(Dispatcher::class))->publish(new OutboxMessage(
        id: $id,
        aggregateType: 'invoice',
        aggregateId: $aggregateId,
        type: 'invoice.finalized',
        payload: ['number' => 'ACME-2026-000117', 'total' => 184200],
        headers: ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'],
        occurredAt: new DateTimeImmutable('2026-09-06T09:00:00+00:00'),
    ));

    Bus::assertDispatched(
        DeliverIntegrationEvent::class,
        static fn(DeliverIntegrationEvent $job): bool => $job->id === $id->value
            && $job->aggregateType === 'invoice'
            && $job->aggregateId === $aggregateId->value
            && $job->type === 'invoice.finalized'
            && $job->payload === ['number' => 'ACME-2026-000117', 'total' => 184200]
            && $job->headers === ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'],
    );
});

it('carries the instant as a string, so a job queued by one deploy survives the next', function (): void {
    Bus::fake();

    new QueueOutboxPublisher(app(Dispatcher::class))->publish(new OutboxMessage(
        id: Uuid::fromString('01932b1c-4f00-7000-8000-0123456789ab'),
        aggregateType: 'invoice',
        aggregateId: Uuid::fromString('01932b1c-4f00-7000-8000-0123456789ac'),
        type: 'invoice.finalized',
        payload: [],
        headers: [],
        occurredAt: new DateTimeImmutable('2026-09-06T09:00:00.123456+00:00'),
    ));

    Bus::assertDispatched(
        DeliverIntegrationEvent::class,
        static fn(DeliverIntegrationEvent $job): bool => str_starts_with($job->occurredAt, '2026-09-06T09:00:00.123'),
    );
});
