<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Metered\Shared\Application\Outbox\OutboxWriter;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Outbox\OutboxMessage;
use Metered\Shared\Infrastructure\Outbox\OutboxRelay;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Tests\Support\RecordingOutboxPublisher;

function relayWith(RecordingOutboxPublisher $publisher, int $maxAttempts = 10): OutboxRelay
{
    return new OutboxRelay(
        app(DatabaseManager::class),
        $publisher,
        app(ClockInterface::class),
        new NullLogger(),
        'pgsql',
        $maxAttempts,
    );
}

/**
 * @param  array<string, mixed>  $payload
 */
function appendMessage(string $type = 'invoice.finalized', array $payload = ['total' => 1999]): OutboxMessage
{
    $ids = app(IdentifierGenerator::class);

    $message = new OutboxMessage(
        id: $ids->generate(),
        aggregateType: 'invoice',
        aggregateId: $ids->generate(),
        type: $type,
        payload: $payload,
        headers: ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'],
        occurredAt: app(ClockInterface::class)->now(),
    );

    app(OutboxWriter::class)->append($message);

    return $message;
}

it('publishes a committed message and marks it published', function (): void {
    $message = appendMessage();
    $publisher = new RecordingOutboxPublisher();

    expect(relayWith($publisher)->relayBatch(10))->toBe(1)
        ->and($publisher->published)->toHaveCount(1);

    $delivered = $publisher->published[0];

    expect($delivered->id->value)->toBe($message->id->value)
        ->and($delivered->type)->toBe('invoice.finalized')
        ->and($delivered->payload)->toBe(['total' => 1999])
        ->and($delivered->headers['traceparent'])->toBe('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');

    $row = DB::table('outbox_messages')->where('id', $message->id->value)->first();

    expect($row?->published_at)->not->toBeNull()
        ->and($row?->attempts)->toBe(1);
});

it('never publishes the same message twice', function (): void {
    appendMessage();
    $publisher = new RecordingOutboxPublisher();
    $relay = relayWith($publisher);

    expect($relay->relayBatch(10))->toBe(1)
        ->and($relay->relayBatch(10))->toBe(0)
        ->and($publisher->published)->toHaveCount(1);
});

it('survives the gap between committing and dispatching', function (): void {
    // The failure the outbox exists for: the state change commits, and the
    // process dies before anything is dispatched. Nothing dispatches here at
    // all — and the event still goes out on the relay's next pass.
    DB::transaction(function (): void {
        appendMessage('subscription.created');
    });

    $publisher = new RecordingOutboxPublisher();

    expect(relayWith($publisher)->relayBatch(10))->toBe(1)
        ->and($publisher->publishedTypes())->toBe(['subscription.created']);
});

it('leaves a message unpublished when publishing fails, and records why', function (): void {
    $message = appendMessage();
    $failing = new RecordingOutboxPublisher('the queue is unreachable');

    expect(relayWith($failing)->relayBatch(10))->toBe(0);

    $row = DB::table('outbox_messages')->where('id', $message->id->value)->first();

    expect($row?->published_at)->toBeNull()
        ->and($row?->attempts)->toBe(1)
        ->and($row?->last_error)->toContain('the queue is unreachable');

    // And the next pass, with a working queue, delivers it.
    $working = new RecordingOutboxPublisher();

    expect(relayWith($working)->relayBatch(10))->toBe(1);
});

it('gives up on a message that has failed too often, without blocking the rest', function (): void {
    $poison = appendMessage('poison.event');
    DB::table('outbox_messages')->where('id', $poison->id->value)->update(['attempts' => 10]);
    appendMessage('healthy.event');

    $publisher = new RecordingOutboxPublisher();

    expect(relayWith($publisher, maxAttempts: 10)->relayBatch(10))->toBe(1)
        ->and($publisher->publishedTypes())->toBe(['healthy.event']);
});

it('publishes in the order things happened', function (): void {
    $clock = app(ClockInterface::class);
    $ids = app(IdentifierGenerator::class);
    $writer = app(OutboxWriter::class);

    foreach (['third' => '+2 minutes', 'first' => '-2 minutes', 'second' => 'now'] as $type => $offset) {
        $writer->append(new OutboxMessage(
            id: $ids->generate(),
            aggregateType: 'invoice',
            aggregateId: $ids->generate(),
            type: $type,
            payload: [],
            headers: [],
            occurredAt: $clock->now()->modify($offset),
        ));
    }

    $publisher = new RecordingOutboxPublisher();
    relayWith($publisher)->relayBatch(10);

    expect($publisher->publishedTypes())->toBe(['first', 'second', 'third']);
});

it('claims no more than the batch it was asked for', function (): void {
    foreach (range(1, 5) as $i) {
        appendMessage('event.' . $i);
    }

    $publisher = new RecordingOutboxPublisher();

    expect(relayWith($publisher)->relayBatch(2))->toBe(2)
        ->and($publisher->published)->toHaveCount(2);
});
