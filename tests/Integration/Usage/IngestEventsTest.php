<?php

declare(strict_types=1);

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Metered\Tenancy\Domain\Project;
use Metered\Tenancy\Domain\Scope;
use Metered\Usage\Application\Stream\StreamDepth;
use Metered\Usage\Infrastructure\Redis\StreamEnvelope;

use function Pest\Laravel\postJson;

use Tests\Support\TenantFactory;
use Tests\Support\UsageStream;

/**
 * The hot path, against a real Redis.
 *
 * A fake stream would prove the controller calls something; what has to be
 * true is that a batch lands in the stream, in one round trip, in the shape
 * the consumer will read (ADR-0003).
 */
beforeEach(function (): void {
    stream()->command('del', [UsageStream::key()]);
});

function stream(): PhpRedisConnection
{
    $connection = app(RedisFactory::class)->connection('usage');

    return $connection instanceof PhpRedisConnection
        ? $connection
        : throw new RuntimeException('These tests need the phpredis client.');
}

/**
 * @return array<string, string>
 */
function ingestionHeaders(Project $project): array
{
    ['secret' => $secret] = TenantFactory::apiKey($project, [Scope::UsageWrite]);

    return ['Authorization' => 'Bearer ' . $secret->reveal()];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function anEvent(array $overrides = []): array
{
    return [
        'event_id' => 'evt_1',
        'meter_code' => 'api.requests',
        'customer_ref' => 'cus_4471',
        'quantity' => '2.5',
        'occurred_at' => '2026-09-22T11:00:00+00:00',
        ...$overrides,
    ];
}

/**
 * Every message in the stream, oldest first, as flat field maps.
 *
 * @return list<array<string, string>>
 */
function streamed(): array
{
    $entries = stream()->command('xrange', [UsageStream::key(), '-', '+']);
    $messages = [];

    foreach (is_array($entries) ? $entries : [] as $fields) {
        $message = [];

        foreach (is_array($fields) ? $fields : [] as $name => $value) {
            $message[(string) $name] = is_scalar($value) ? (string) $value : '';
        }

        $messages[] = $message;
    }

    return $messages;
}

it('accepts a batch and answers with a handle to ask about it later', function (): void {
    $project = TenantFactory::tenant();

    $response = postJson('/api/v1/usage/events', ['events' => [anEvent(), anEvent(['event_id' => 'evt_2'])]], ingestionHeaders($project));

    $response->assertStatus(202)
        ->assertJsonPath('accepted', 2)
        ->assertJsonStructure(['accepted', 'request_id']);

    expect(streamed())->toHaveCount(2);
});

it('writes the batch in the shape the consumer reads, tenant and all', function (): void {
    $project = TenantFactory::tenant();

    postJson('/api/v1/usage/events', ['events' => [anEvent(['properties' => ['region' => 'eu-central']])]], ingestionHeaders($project))
        ->assertStatus(202);

    $fields = streamed()[0];

    expect($fields['v'])->toBe(StreamEnvelope::VERSION)
        ->and($fields['project_id'])->toBe($project->id->value)
        ->and($fields['organization_id'])->toBe($project->organizationId->value)
        ->and($fields['event_id'])->toBe('evt_1')
        ->and($fields['meter_code'])->toBe('api.requests')
        ->and($fields['customer_ref'])->toBe('cus_4471')
        // A string, not a JSON number: a quantity that goes through a float
        // on its way to a billing calculation is a rounding error with a
        // customer's name on it.
        ->and($fields['quantity'])->toBe('2.500000')
        ->and($fields['properties'])->toBe('{"region":"eu-central"}')
        ->and($fields['received_at'])->not->toBeEmpty();
});

it('reads a message back into exactly what was sent', function (): void {
    $project = TenantFactory::tenant();

    postJson('/api/v1/usage/events', ['events' => [anEvent(['occurred_at' => '2026-09-22T14:00:00+03:00'])]], ingestionHeaders($project))
        ->assertStatus(202);

    $envelope = StreamEnvelope::decode(streamed()[0]);

    expect($envelope?->tenant->projectId->value)->toBe($project->id->value)
        ->and((string) $envelope?->event->eventId)->toBe('evt_1')
        ->and((string) $envelope?->event->quantity)->toBe('2.500000')
        // The offset the client wrote is not the offset we keep.
        ->and($envelope?->event->occurredAt->format(DATE_ATOM))->toBe('2026-09-22T11:00:00+00:00');
});

it('takes a batch of a hundred and refuses a hundred and one', function (): void {
    $project = TenantFactory::tenant();
    $headers = ingestionHeaders($project);

    $hundred = array_map(
        static fn(int $i): array => anEvent(['event_id' => 'evt_' . $i]),
        range(1, 100),
    );

    postJson('/api/v1/usage/events', ['events' => $hundred], $headers)->assertStatus(202);
    postJson('/api/v1/usage/events', ['events' => [...$hundred, anEvent(['event_id' => 'evt_101'])]], $headers)
        ->assertStatus(422);

    expect(streamed())->toHaveCount(100);
});

it('answers a malformed event with a pointer to the one that was wrong', function (array $event, string $expected): void {
    $project = TenantFactory::tenant();

    $response = postJson(
        '/api/v1/usage/events',
        ['events' => [anEvent(), $event]],
        ingestionHeaders($project),
    );

    $response->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json');

    expect($response->json('pointer') ?? $response->json('errors'))->not->toBeNull()
        ->and(json_encode($response->json()))->toContain($expected);

    // Nothing from a rejected request reaches the stream: a batch is taken
    // whole or not at all.
    expect(streamed())->toHaveCount(0);
})->with([
    'no event id' => [['meter_code' => 'api.requests', 'customer_ref' => 'cus_1', 'quantity' => '1', 'occurred_at' => '2026-09-22T11:00:00+00:00'], 'event_id'],
    'a quantity that is not a number' => [[...anEvent(), 'quantity' => 'many'], 'quantity'],
    'a negative quantity' => [[...anEvent(), 'quantity' => '-1'], 'negative'],
    'a timestamp that is not one' => [[...anEvent(), 'occurred_at' => 'yesterday afternoon'], 'occurred_at'],
    'an event id with a space in it' => [[...anEvent(), 'event_id' => 'evt 1'], 'event id'],
    'nested properties' => [[...anEvent(), 'properties' => ['tags' => ['a']]], 'nested'],
]);

it('refuses a key that may write usage for another project', function (): void {
    $acme = TenantFactory::tenant('acme');
    $rival = TenantFactory::tenant('north-wind');

    postJson('/api/v1/usage/events', ['events' => [anEvent()]], ingestionHeaders($rival))->assertStatus(202);

    // The tenant is the key's, never the body's: there is no field a client
    // could put another project's id in.
    expect(streamed()[0]['project_id'])->toBe($rival->id->value)
        ->and($rival->id->value)->not->toBe($acme->id->value);
});

it('refuses a request with no key at all', function (): void {
    postJson('/api/v1/usage/events', ['events' => [anEvent()]])->assertStatus(401);

    expect(streamed())->toHaveCount(0);
});

it('refuses a key without the usage:write scope', function (): void {
    $project = TenantFactory::tenant();
    ['secret' => $secret] = TenantFactory::apiKey($project, [Scope::Admin]);

    postJson('/api/v1/usage/events', ['events' => [anEvent()]], ['Authorization' => 'Bearer ' . $secret->reveal()])
        ->assertStatus(403);

    expect(streamed())->toHaveCount(0);
});

it('counts what is waiting, which is what backpressure is decided on', function (): void {
    $project = TenantFactory::tenant();

    expect(app(StreamDepth::class)->pending())->toBe(0);

    postJson('/api/v1/usage/events', ['events' => [anEvent(), anEvent(['event_id' => 'evt_2'])]], ingestionHeaders($project));

    expect(app(StreamDepth::class)->pending())->toBe(2);
});

it('stops counting an event once the consumer has dealt with it', function (): void {
    $project = TenantFactory::tenant();

    postJson('/api/v1/usage/events', ['events' => [anEvent(), anEvent(['event_id' => 'evt_2'])]], ingestionHeaders($project));

    stream()->command('xgroup', ['CREATE', UsageStream::key(), UsageStream::group(), '0', true]);

    expect(app(StreamDepth::class)->pending())->toBe(2);

    // Read both, acknowledge one. A stream keeps every entry until MAXLEN
    // trims it, so its length stays at two throughout — and reading the
    // length is how ingestion used to answer 503 with an idle consumer and
    // nothing at all waiting to be written.
    $read = stream()->command('xreadgroup', [UsageStream::group(), 'test-consumer', [UsageStream::key() => '>'], 2]);

    expect($read)->toBeArray();

    /** @var array<string, array<string, array<string, string>>> $read */
    $ids = array_keys($read[UsageStream::key()] ?? []);

    expect($ids)->toHaveCount(2);
    expect(app(StreamDepth::class)->pending())->toBe(2);

    stream()->command('xack', [UsageStream::key(), UsageStream::group(), [$ids[0]]]);

    expect(app(StreamDepth::class)->pending())->toBe(1);

    stream()->command('xack', [UsageStream::key(), UsageStream::group(), [$ids[1]]]);

    expect(app(StreamDepth::class)->pending())->toBe(0);
    expect(stream()->command('xlen', [UsageStream::key()]))->toBe(2);
});

it('sheds load with a Retry-After once the stream is deeper than it should be', function (): void {
    config(['metered.usage.stream.backpressure_threshold' => 1]);
    $project = TenantFactory::tenant();
    $headers = ingestionHeaders($project);

    postJson('/api/v1/usage/events', ['events' => [anEvent()]], $headers)->assertStatus(202);

    // Accepting a batch the consumer is visibly failing to drain trades a
    // fast 503 now for a stream that never recovers.
    postJson('/api/v1/usage/events', ['events' => [anEvent(['event_id' => 'evt_2'])]], $headers)
        ->assertStatus(503)
        ->assertHeader('Retry-After', '5')
        ->assertHeader('Content-Type', 'application/problem+json');

    expect(streamed())->toHaveCount(1);
});
