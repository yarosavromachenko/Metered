<?php

declare(strict_types=1);

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Domain\Scope;
use Metered\Usage\Infrastructure\Redis\StreamConsumer;
use Metered\Usage\Infrastructure\Redis\StreamEnvelope;
use OpenTelemetry\API\Trace\SpanKind;

use function Pest\Laravel\postJson;

use Tests\Support\CatalogFactory;
use Tests\Support\InMemoryTracing;
use Tests\Support\TenantFactory;
use Tests\Support\UsageStream;

/**
 * The first two hops of ADR-0012 over a real stream: the request's context
 * travels inside every message, and the write that serves many requests
 * links to each of them instead of pretending to be the child of one.
 */
beforeEach(function (): void {
    $redis = app(RedisFactory::class)->connection('usage');
    $redis->command('del', [UsageStream::key()]);
    $redis->command('del', [UsageStream::deadLetter()]);
});

/**
 * @return array{tenant: TenantContext, headers: array<string, string>}
 */
function tracedIngestionTenant(string $slug): array
{
    $project = TenantFactory::tenant($slug);
    CatalogFactory::meter($project->tenant(), 'api.requests');
    CatalogFactory::customer($project->tenant(), 'cus_4471');

    ['secret' => $secret] = TenantFactory::apiKey($project, [Scope::UsageWrite]);

    return ['tenant' => $project->tenant(), 'headers' => ['Authorization' => 'Bearer ' . $secret->reveal()]];
}

/**
 * @param  array<string, string>  $headers
 */
function sendTracedEvent(array $headers, string $eventId): void
{
    postJson('/api/v1/usage/events', ['events' => [[
        'event_id' => $eventId,
        'meter_code' => 'api.requests',
        'customer_ref' => 'cus_4471',
        'quantity' => '1',
        'occurred_at' => now()->subMinute()->toAtomString(),
    ]]], $headers)->assertStatus(202);
}

/**
 * @return list<array<string, string>>
 */
function streamedFields(): array
{
    /** @var array<string, array<string, string>> $entries */
    $entries = app(RedisFactory::class)->connection('usage')->command('xrange', [UsageStream::key(), '-', '+']);

    return array_values($entries);
}

it('writes the request\'s trace context into the message', function (): void {
    $recorder = InMemoryTracing::install();
    ['headers' => $headers] = tracedIngestionTenant('traced-stream');

    sendTracedEvent($headers, 'evt_1');

    $request = $recorder->named('POST /api/v1/usage/events');
    $fields = streamedFields();

    expect($request)->not->toBeNull()
        ->and($fields)->toHaveCount(1)
        ->and($fields[0]['v'])->toBe(StreamEnvelope::VERSION)
        ->and($fields[0]['traceparent'] ?? '')->toContain($request?->getContext()->getTraceId())
        ->and($fields[0]['traceparent'] ?? '')->toContain($request?->getContext()->getSpanId());
});

it('writes no trace fields when tracing is off, and the message still reads', function (): void {
    ['headers' => $headers] = tracedIngestionTenant('untraced-stream');

    sendTracedEvent($headers, 'evt_1');

    $fields = streamedFields()[0];
    $envelope = StreamEnvelope::decode($fields);

    expect($fields)->not->toHaveKey('traceparent')
        ->and($envelope)->not->toBeNull()
        ->and($envelope?->trace)->toBe([]);
});

it('links the batch write to every request whose events it wrote', function (): void {
    $recorder = InMemoryTracing::install();
    ['tenant' => $tenant, 'headers' => $headers] = tracedIngestionTenant('traced-batch');

    sendTracedEvent($headers, 'evt_1');
    sendTracedEvent($headers, 'evt_2');

    $consumer = app(StreamConsumer::class);
    $consumer->ensureGroup();
    $consumer->consumeOnce('test-consumer');

    $requests = $recorder->allNamed('POST /api/v1/usage/events');
    $batch = $recorder->named('usage.batch');

    expect($requests)->toHaveCount(2)
        ->and($batch)->not->toBeNull();

    $linked = array_map(static fn($link): string => $link->getSpanContext()->getSpanId(), $batch?->getLinks() ?? []);

    // Its own trace, not a child of either request: one write, many causes.
    expect($batch?->getParentContext()->isValid())->toBeFalse()
        ->and($batch?->getKind())->toBe(SpanKind::KIND_CONSUMER)
        ->and($linked)->toEqualCanonicalizing([
            $requests[0]->getContext()->getSpanId(),
            $requests[1]->getContext()->getSpanId(),
        ])
        ->and($batch?->getAttributes()->get('messaging.batch.message_count'))->toBe(2)
        ->and($batch?->getAttributes()->get('metered.batch.request_count'))->toBe(2)
        ->and($batch?->getAttributes()->get('metered.project_id'))->toBe($tenant->projectId->value);
});

it('links a request once however many of its events the batch wrote', function (): void {
    $recorder = InMemoryTracing::install();
    ['headers' => $headers] = tracedIngestionTenant('traced-one-request');

    postJson('/api/v1/usage/events', ['events' => array_map(static fn(int $index): array => [
        'event_id' => 'evt_' . $index,
        'meter_code' => 'api.requests',
        'customer_ref' => 'cus_4471',
        'quantity' => '1',
        'occurred_at' => now()->subMinute()->toAtomString(),
    ], range(1, 5))], $headers)->assertStatus(202);

    $consumer = app(StreamConsumer::class);
    $consumer->ensureGroup();
    $consumer->consumeOnce('test-consumer');

    $batch = $recorder->named('usage.batch');

    expect($batch?->getLinks())->toHaveCount(1)
        ->and($batch?->getAttributes()->get('messaging.batch.message_count'))->toBe(5);
});
