<?php

declare(strict_types=1);

use Metered\Shared\Application\Inbox\InboxGuard;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Outbox\OutboxMessage;
use Metered\Shared\Infrastructure\Inbox\IntegrationEventDispatcher;
use Metered\Shared\Infrastructure\Queue\DeliverIntegrationEvent;
use Psr\Clock\ClockInterface;
use Tests\Support\RecordingEventHandler;

it('rebuilds the message from its scalars and hands it to the handlers', function (): void {
    $handler = new RecordingEventHandler('test.recorder', ['invoice.finalized']);

    $ids = app(IdentifierGenerator::class);
    $original = new OutboxMessage(
        id: $ids->generate(),
        aggregateType: 'invoice',
        aggregateId: $ids->generate(),
        type: 'invoice.finalized',
        payload: ['number' => 'ACME-2026-000117', 'nested' => ['late' => true]],
        headers: ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'],
        occurredAt: app(ClockInterface::class)->now(),
    );

    $job = DeliverIntegrationEvent::fromMessage($original);
    $job->handle(new IntegrationEventDispatcher([$handler], app(InboxGuard::class)));

    $received = $handler->received;

    expect($received)->toBeInstanceOf(OutboxMessage::class);
    assert($received instanceof OutboxMessage);

    expect($received->id->value)->toBe($original->id->value)
        ->and($received->aggregateId->value)->toBe($original->aggregateId->value)
        ->and($received->aggregateType)->toBe('invoice')
        ->and($received->type)->toBe('invoice.finalized')
        ->and($received->payload)->toBe(['number' => 'ACME-2026-000117', 'nested' => ['late' => true]])
        ->and($received->headers)->toBe(['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'])
        ->and($received->occurredAt->format('Y-m-d H:i:s'))->toBe($original->occurredAt->format('Y-m-d H:i:s'));
});
