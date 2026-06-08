<?php

declare(strict_types=1);

use Metered\Shared\Application\Inbox\InboxGuard;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Outbox\OutboxMessage;
use Metered\Shared\Infrastructure\Inbox\IntegrationEventDispatcher;
use Psr\Clock\ClockInterface;
use Tests\Support\CallLog;
use Tests\Support\RecordingEventHandler;

function anyMessage(string $type): OutboxMessage
{
    $ids = app(IdentifierGenerator::class);

    return new OutboxMessage(
        id: $ids->generate(),
        aggregateType: 'invoice',
        aggregateId: $ids->generate(),
        type: $type,
        payload: [],
        headers: [],
        occurredAt: app(ClockInterface::class)->now(),
    );
}

it('gives the event only to the handlers that asked for it', function (): void {
    $log = new CallLog();

    $dispatcher = new IntegrationEventDispatcher(
        [
            new RecordingEventHandler('invoice-listener', ['invoice.finalized'], $log),
            new RecordingEventHandler('subscription-listener', ['subscription.created'], $log),
            new RecordingEventHandler('second-invoice-listener', ['invoice.finalized'], $log),
        ],
        app(InboxGuard::class),
    );

    expect($dispatcher->dispatch(anyMessage('invoice.finalized')))->toBe(2)
        ->and($log->entries)->toBe(['invoice-listener', 'second-invoice-listener']);
});

it('does nothing when nobody is listening', function (): void {
    $log = new CallLog();
    $dispatcher = new IntegrationEventDispatcher(
        [new RecordingEventHandler('invoice-listener', ['invoice.finalized'], $log)],
        app(InboxGuard::class),
    );

    expect($dispatcher->dispatch(anyMessage('nobody.cares')))->toBe(0)
        ->and($log->entries)->toBe([]);
});

it('runs each handler once however often the message arrives', function (): void {
    $log = new CallLog();
    $dispatcher = new IntegrationEventDispatcher(
        [
            new RecordingEventHandler('billing', ['invoice.finalized'], $log),
            new RecordingEventHandler('webhooks', ['invoice.finalized'], $log),
        ],
        app(InboxGuard::class),
    );

    $message = anyMessage('invoice.finalized');

    expect($dispatcher->dispatch($message))->toBe(2)
        ->and($dispatcher->dispatch($message))->toBe(0)
        ->and($dispatcher->dispatch($message))->toBe(0)
        // Two handlers, three deliveries, two executions: the inbox claim is
        // keyed by handler, so neither steals the other's turn.
        ->and($log->entries)->toBe(['billing', 'webhooks']);
});
