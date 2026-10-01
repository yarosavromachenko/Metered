<?php

declare(strict_types=1);

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Testing\TestResponse;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Domain\Scope;
use Metered\Usage\Infrastructure\Redis\StreamConsumer;
use Metered\Usage\Infrastructure\Redis\StreamGauges;

use function Pest\Laravel\postJson;

use Symfony\Component\HttpFoundation\Response;
use Tests\Support\CatalogFactory;
use Tests\Support\InMemoryMetrics;
use Tests\Support\TenantFactory;
use Tests\Support\UsageStream;

/**
 * The ingestion path's instruments, recorded by the real endpoint, stream
 * and consumer, and read back as a dashboard would query them.
 */
beforeEach(function (): void {
    $redis = app(RedisFactory::class)->connection('usage');
    $redis->command('del', [UsageStream::key()]);
    $redis->command('del', [UsageStream::deadLetter()]);
});

/**
 * @return array{tenant: TenantContext, headers: array<string, string>}
 */
function meteredIngestionTenant(string $slug): array
{
    $project = TenantFactory::tenant($slug);
    CatalogFactory::meter($project->tenant(), 'api.requests');
    CatalogFactory::customer($project->tenant(), 'cus_4471');

    ['secret' => $secret] = TenantFactory::apiKey($project, [Scope::UsageWrite]);

    return ['tenant' => $project->tenant(), 'headers' => ['Authorization' => 'Bearer ' . $secret->reveal()]];
}

/**
 * @param  array<string, string>  $headers
 * @param  array<string, string>  $overrides
 * @return TestResponse<Response>
 */
function postMeteredEvent(array $headers, string $eventId, array $overrides = []): TestResponse
{
    return postJson('/api/v1/usage/events', ['events' => [[
        'event_id' => $eventId,
        'meter_code' => 'api.requests',
        'customer_ref' => 'cus_4471',
        'quantity' => '1',
        'occurred_at' => now()->subMinute()->toAtomString(),
        ...$overrides,
    ]]], $headers);
}

function consumeMeteredStream(): void
{
    $consumer = app(StreamConsumer::class);
    $consumer->ensureGroup();
    $consumer->consumeOnce('test-consumer');
}

it('counts and times every ingestion answer by status, refusals included', function (): void {
    $metrics = InMemoryMetrics::install();
    ['headers' => $headers] = meteredIngestionTenant('metered-ingest');

    postMeteredEvent($headers, 'evt_1')->assertStatus(202);
    postMeteredEvent($headers, 'evt_2')->assertStatus(202);
    postMeteredEvent(['Authorization' => 'Bearer mk_test_nope'], 'evt_3')->assertStatus(401);

    expect($metrics->counted('ingest.requests', ['status' => '202']))->toBe(2)
        ->and($metrics->counted('ingest.requests', ['status' => '401']))->toBe(1)
        ->and($metrics->histogram('ingest.duration', ['status' => '202'])['count'])->toBe(2);
});

it('times each batch write and records its size', function (): void {
    $metrics = InMemoryMetrics::install();
    ['headers' => $headers] = meteredIngestionTenant('metered-batch');
    postMeteredEvent($headers, 'evt_1');
    postMeteredEvent($headers, 'evt_2');

    consumeMeteredStream();

    expect($metrics->histogram('usage.batch_write.duration')['count'])->toBe(1)
        ->and($metrics->histogram('usage.batch.size'))->toBe(['count' => 1, 'sum' => 2]);
});

it('counts rejected events by reason', function (): void {
    $metrics = InMemoryMetrics::install();
    ['headers' => $headers] = meteredIngestionTenant('metered-rejections');
    postMeteredEvent($headers, 'evt_1', ['meter_code' => 'api.unknown']);
    postMeteredEvent($headers, 'evt_2', ['meter_code' => 'api.unknown']);
    postMeteredEvent($headers, 'evt_3', ['customer_ref' => 'cus_nobody']);

    consumeMeteredStream();

    expect($metrics->counted('usage.events.rejected', ['reason' => 'unknown_meter']))->toBe(2)
        ->and($metrics->counted('usage.events.rejected', ['reason' => 'unknown_customer']))->toBe(1);
});

it('reads the stream\'s depth, length and dead letters', function (): void {
    ['headers' => $headers] = meteredIngestionTenant('metered-gauges');
    postMeteredEvent($headers, 'evt_1');
    postMeteredEvent($headers, 'evt_2');
    app(RedisFactory::class)->connection('usage')->command('xadd', [UsageStream::deadLetter(), '*', ['_reason' => 'malformed']]);

    $readings = [];

    foreach (app(StreamGauges::class)->read() as $reading) {
        $readings[$reading->gauge->name] = $reading->value;
    }

    expect($readings)->toMatchArray([
        'usage.stream.pending' => 2,
        'usage.stream.length' => 2,
        'dlq.size' => 1,
    ]);
});

it('reads how much of its memory the usage Redis has used', function (): void {
    $redis = app(RedisFactory::class)->connection('usage');
    $limit = $redis->command('config', ['get', 'maxmemory']);
    $limit = is_array($limit) && is_numeric($limit['maxmemory'] ?? null) ? (int) $limit['maxmemory'] : -1;
    $readings = [];

    foreach (app(StreamGauges::class)->read() as $reading) {
        $readings[$reading->gauge->name] = $reading->value;
    }

    // The limit is whatever this Redis runs with: 0, "none", on a runner's
    // service container; the configured maxmemory under compose.
    expect($readings['usage.redis.memory.used'] ?? 0)->toBeGreaterThan(0)
        ->and($readings['usage.redis.memory.limit'] ?? null)->toBe($limit);
});
