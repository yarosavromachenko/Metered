<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Metered\Shared\Application\Outbox\OutboxWriter;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Outbox\OutboxMessage;
use Metered\Shared\Infrastructure\Outbox\OutboxRelay;
use Metered\Shared\Infrastructure\Outbox\QueueOutboxPublisher;
use Metered\Shared\Infrastructure\Queue\DeliverIntegrationEvent;
use OpenTelemetry\API\Trace\SpanKind;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Tests\Support\InMemoryTracing;

/**
 * The outbox hops of ADR-0012: the writer records the context of whatever
 * changed state, and the relay publishes inside that trace, so the job it
 * queues continues it. The queue is `sync` here, so the job runs inside the
 * relay's call and its span can be asserted on directly.
 *
 * @param  array<string, string>  $headers
 */
function tracedOutboxMessage(array $headers = []): OutboxMessage
{
    $ids = app(IdentifierGenerator::class);

    return new OutboxMessage(
        id: $ids->generate(),
        aggregateType: 'invoice',
        aggregateId: $ids->generate(),
        type: 'test.traced',
        payload: ['total' => 1999],
        headers: $headers,
        occurredAt: app(ClockInterface::class)->now(),
    );
}

/**
 * @return array<string, string>
 */
function storedHeaders(OutboxMessage $message): array
{
    $raw = DB::table('outbox_messages')->where('id', $message->id->value)->value('headers');

    /** @var array<string, string> */
    return json_decode(is_string($raw) ? $raw : '{}', true, flags: JSON_THROW_ON_ERROR);
}

it('stores the writer\'s trace context in the message headers', function (): void {
    $recorder = InMemoryTracing::install();
    $request = $recorder->tracing->tracer()->spanBuilder('POST /api/v1/subscriptions')->startSpan();
    $scope = $request->activate();

    $message = tracedOutboxMessage();
    app(OutboxWriter::class)->append($message);

    $scope->detach();
    $request->end();

    expect(storedHeaders($message)['traceparent'] ?? '')
        ->toContain($request->getContext()->getTraceId())
        ->toContain($request->getContext()->getSpanId());
});

it('keeps a context the producer set itself', function (): void {
    $recorder = InMemoryTracing::install();
    $request = $recorder->tracing->tracer()->spanBuilder('POST /api/v1/subscriptions')->startSpan();
    $scope = $request->activate();

    $message = tracedOutboxMessage(['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']);
    app(OutboxWriter::class)->append($message);

    $scope->detach();
    $request->end();

    expect(storedHeaders($message))->toBe(['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']);
});

it('stores no context when nothing is being traced', function (): void {
    $message = tracedOutboxMessage();
    app(OutboxWriter::class)->append($message);

    expect(storedHeaders($message))->toBe([]);
});

it('publishes inside the trace the message was written in, and the job continues it', function (): void {
    $recorder = InMemoryTracing::install();
    $request = $recorder->tracing->tracer()->spanBuilder('POST /api/v1/subscriptions')->startSpan();
    $scope = $request->activate();

    app(OutboxWriter::class)->append(tracedOutboxMessage());

    $scope->detach();
    $request->end();

    // The relay: another process, later, with no ambient context of its own.
    $relay = new OutboxRelay(
        app(DatabaseManager::class),
        new QueueOutboxPublisher(app(Dispatcher::class)),
        app(ClockInterface::class),
        new NullLogger(),
        testConnection(),
        10,
        $recorder->tracing,
    );

    expect($relay->relayBatch(10))->toBe(1);

    $publish = $recorder->named('outbox publish test.traced');
    $job = $recorder->named(DeliverIntegrationEvent::class);
    $traceId = $request->getContext()->getTraceId();

    expect($publish)->not->toBeNull()
        ->and($job)->not->toBeNull()
        ->and($publish?->getKind())->toBe(SpanKind::KIND_PRODUCER)
        ->and($publish?->getContext()->getTraceId())->toBe($traceId)
        ->and($publish?->getParentContext()->getSpanId())->toBe($request->getContext()->getSpanId())
        ->and($job?->getContext()->getTraceId())->toBe($traceId)
        ->and($job?->getParentContext()->getSpanId())->toBe($publish?->getContext()->getSpanId());
});
