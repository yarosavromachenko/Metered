<?php

declare(strict_types=1);

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\DB;
use Metered\Shared\Domain\Metering\Aggregation;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Domain\Project;
use Metered\Tenancy\Domain\Scope;
use Metered\Usage\Infrastructure\Redis\StreamConsumer;

use function Pest\Laravel\postJson;

use Tests\Support\CatalogFactory;
use Tests\Support\TenantFactory;
use Tests\Support\UsageStream;

/**
 * Ingestion end to end, over a real stream and a real database: post a batch
 * to the endpoint, run one pass of the consumer, look at what the tables say.
 *
 * Deliberately not a test of the processor with everything faked. The
 * properties that matter here — a redelivery adding nothing, an
 * acknowledgement that only happens after a commit, a poison message stepping
 * aside — are properties of the arrangement, and a fake stream has none of
 * them.
 */
beforeEach(function (): void {
    redis()->command('del', [UsageStream::key()]);
    redis()->command('del', [UsageStream::deadLetter()]);

    // Deduplication claims are left alone. Each is keyed by its project, and
    // every test makes a new one, so no claim can reach another test; and
    // deleting them all would reach into the other processes of a parallel
    // run and take claims their tests are about to assert on.
});

function redis(): PhpRedisConnection
{
    $connection = app(RedisFactory::class)->connection('usage');

    return $connection instanceof PhpRedisConnection
        ? $connection
        : throw new RuntimeException('These tests need the phpredis client.');
}

/**
 * A project with a meter, a customer and a key that may write usage.
 *
 * @return array{project: Project, tenant: TenantContext, headers: array<string, string>}
 */
function ingestionTenant(string $slug = 'acme', Aggregation $aggregation = Aggregation::Sum): array
{
    $project = TenantFactory::tenant($slug);
    CatalogFactory::meter($project->tenant(), 'api.requests', $aggregation);
    CatalogFactory::customer($project->tenant(), 'cus_4471');

    ['secret' => $secret] = TenantFactory::apiKey($project, [Scope::UsageWrite]);

    return [
        'project' => $project,
        'tenant' => $project->tenant(),
        'headers' => ['Authorization' => 'Bearer ' . $secret->reveal()],
    ];
}

/**
 * @param  array<string, string>  $headers
 * @param  list<array<string, mixed>>  $events
 */
function send(array $headers, array $events): void
{
    postJson('/api/v1/usage/events', ['events' => $events], $headers)->assertStatus(202);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function usageEvent(array $overrides = []): array
{
    return [
        'event_id' => 'evt_1',
        'meter_code' => 'api.requests',
        'customer_ref' => 'cus_4471',
        'quantity' => '2.5',
        'occurred_at' => now()->subMinute()->toAtomString(),
        ...$overrides,
    ];
}

function consume(): void
{
    $consumer = app(StreamConsumer::class);
    $consumer->ensureGroup();
    $consumer->consumeOnce('test-consumer');
}

it('writes the events it read, and the aggregate they fold into', function (): void {
    ['tenant' => $tenant, 'headers' => $headers] = ingestionTenant();

    send($headers, [usageEvent(), usageEvent(['event_id' => 'evt_2', 'quantity' => '1.5'])]);
    consume();

    $aggregate = DB::table('usage_aggregates')->where('project_id', $tenant->projectId->value)->first();

    expect(DB::table('usage_events')->where('project_id', $tenant->projectId->value)->count())->toBe(2)
        ->and($aggregate?->quantity)->toBe('4.000000')
        ->and($aggregate?->event_count)->toBe(2);
});

it('counts an event once however often the same batch is delivered', function (): void {
    ['tenant' => $tenant, 'headers' => $headers] = ingestionTenant();

    send($headers, [usageEvent()]);
    consume();

    // The same event again, as a client's retry after a timeout looks.
    send($headers, [usageEvent()]);
    consume();

    $aggregate = DB::table('usage_aggregates')->where('project_id', $tenant->projectId->value)->first();

    expect(DB::table('usage_events')->where('project_id', $tenant->projectId->value)->count())->toBe(1)
        ->and($aggregate?->quantity)->toBe('2.500000')
        ->and($aggregate?->event_count)->toBe(1);
});

it('counts an event once when the duplicate is inside one batch', function (): void {
    ['tenant' => $tenant, 'headers' => $headers] = ingestionTenant();

    send($headers, [usageEvent(), usageEvent(), usageEvent(['event_id' => 'evt_2'])]);
    consume();

    expect(DB::table('usage_events')->where('project_id', $tenant->projectId->value)->count())->toBe(2);
});

it('catches the resend the database cannot: same id, corrected timestamp', function (): void {
    ['tenant' => $tenant, 'headers' => $headers] = ingestionTenant();

    send($headers, [usageEvent(['occurred_at' => now()->subHours(2)->toAtomString()])]);
    consume();

    // A different occurred_at means a different primary key, so the unique
    // index cannot see this as a duplicate (ADR-0002). The Redis claim can.
    send($headers, [usageEvent(['occurred_at' => now()->subHour()->toAtomString()])]);
    consume();

    expect(DB::table('usage_events')->where('project_id', $tenant->projectId->value)->count())->toBe(1);
});

it('folds a count meter by occurrences and a max meter by peak', function (Aggregation $aggregation, string $expected): void {
    ['tenant' => $tenant, 'headers' => $headers] = ingestionTenant('acme', $aggregation);

    send($headers, [
        usageEvent(['event_id' => 'evt_1', 'quantity' => '3']),
        usageEvent(['event_id' => 'evt_2', 'quantity' => '11']),
        usageEvent(['event_id' => 'evt_3', 'quantity' => '7']),
    ]);
    consume();

    expect(DB::table('usage_aggregates')->where('project_id', $tenant->projectId->value)->value('quantity'))
        ->toBe($expected);
})->with([
    'sum adds them up' => [Aggregation::Sum, '21.000000'],
    'count ignores what they carry' => [Aggregation::Count, '3.000000'],
    'max keeps the highest' => [Aggregation::Max, '11.000000'],
]);

it('keeps a max meter at its peak when a smaller value is redelivered', function (): void {
    ['tenant' => $tenant, 'headers' => $headers] = ingestionTenant('acme', Aggregation::Max);

    send($headers, [usageEvent(['event_id' => 'evt_high', 'quantity' => '11'])]);
    consume();
    send($headers, [usageEvent(['event_id' => 'evt_low', 'quantity' => '2'])]);
    consume();

    expect(DB::table('usage_aggregates')->where('project_id', $tenant->projectId->value)->value('quantity'))
        ->toBe('11.000000');
});

it('separates buckets by the hour the event happened in', function (): void {
    ['tenant' => $tenant, 'headers' => $headers] = ingestionTenant();

    send($headers, [
        usageEvent(['event_id' => 'evt_1', 'occurred_at' => '2026-09-22T10:15:00+00:00']),
        usageEvent(['event_id' => 'evt_2', 'occurred_at' => '2026-09-22T10:45:00+00:00']),
        usageEvent(['event_id' => 'evt_3', 'occurred_at' => '2026-09-22T11:05:00+00:00']),
    ]);
    consume();

    $buckets = DB::table('usage_aggregates')
        ->where('project_id', $tenant->projectId->value)
        ->orderBy('bucket_start')
        ->pluck('event_count')
        ->all();

    expect($buckets)->toBe([2, 1]);
});

it('rejects an event naming a meter or a customer that does not exist', function (string $field, string $value, string $reason): void {
    ['tenant' => $tenant, 'headers' => $headers] = ingestionTenant();

    send($headers, [usageEvent([$field => $value])]);
    consume();

    $rejection = DB::table('usage_event_rejections')->where('project_id', $tenant->projectId->value)->first();

    expect(DB::table('usage_events')->where('project_id', $tenant->projectId->value)->count())->toBe(0)
        ->and($rejection?->reason)->toBe($reason)
        ->and($rejection?->event_id)->toBe('evt_1')
        // The payload is kept, because "what did we actually send?" has no
        // other answer once the client has been told 202.
        ->and($rejection?->payload)->toContain('api.');
})->with([
    'an unknown meter' => ['meter_code', 'api.responses', 'unknown_meter'],
    'an unknown customer' => ['customer_ref', 'cus_9999', 'unknown_customer'],
]);

it('rejects an event older than the acceptance window, and says why', function (): void {
    ['tenant' => $tenant, 'headers' => $headers] = ingestionTenant();

    send($headers, [usageEvent(['occurred_at' => now()->subDays(8)->toAtomString()])]);
    consume();

    $rejection = DB::table('usage_event_rejections')->where('project_id', $tenant->projectId->value)->first();

    expect($rejection?->reason)->toBe('too_old')
        ->and($rejection?->detail)->toContain('may already be invoiced');
});

it('rejects an event dated further ahead than a clock drifts', function (): void {
    ['tenant' => $tenant, 'headers' => $headers] = ingestionTenant();

    send($headers, [usageEvent(['occurred_at' => now()->addHour()->toAtomString()])]);
    consume();

    expect(DB::table('usage_event_rejections')->where('project_id', $tenant->projectId->value)->value('reason'))
        ->toBe('in_the_future');
});

it('accepts an event a few minutes ahead, because clocks drift', function (): void {
    ['tenant' => $tenant, 'headers' => $headers] = ingestionTenant();

    send($headers, [usageEvent(['occurred_at' => now()->addMinutes(2)->toAtomString()])]);
    consume();

    expect(DB::table('usage_events')->where('project_id', $tenant->projectId->value)->count())->toBe(1)
        ->and(DB::table('usage_event_rejections')->where('project_id', $tenant->projectId->value)->count())->toBe(0);
});

it('takes a batch belonging to two tenants and keeps them apart', function (): void {
    $acme = ingestionTenant('acme');
    $rival = ingestionTenant('north-wind');

    send($acme['headers'], [usageEvent(['event_id' => 'evt_acme'])]);
    send($rival['headers'], [usageEvent(['event_id' => 'evt_rival'])]);
    consume();

    expect(DB::table('usage_events')->where('project_id', $acme['tenant']->projectId->value)->pluck('event_id')->all())
        ->toBe(['evt_acme'])
        ->and(DB::table('usage_events')->where('project_id', $rival['tenant']->projectId->value)->pluck('event_id')->all())
        ->toBe(['evt_rival']);
});

it('acknowledges what it wrote, so a second pass has nothing to do', function (): void {
    ['tenant' => $tenant, 'headers' => $headers] = ingestionTenant();

    send($headers, [usageEvent()]);
    consume();
    consume();

    $pending = redis()->command('xpending', [UsageStream::key(), UsageStream::group()]);

    expect(DB::table('usage_events')->where('project_id', $tenant->projectId->value)->count())->toBe(1)
        ->and(is_array($pending) ? $pending[0] : 0)->toBe(0);
});
