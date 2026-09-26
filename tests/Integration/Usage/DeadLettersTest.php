<?php

declare(strict_types=1);

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Domain\Scope;
use Metered\Usage\Application\Ingestion\BatchProcessor;
use Metered\Usage\Infrastructure\Redis\DeadLetters;
use Metered\Usage\Infrastructure\Redis\ReplayOutcome;
use Metered\Usage\Infrastructure\Redis\StreamConsumer;

use function Pest\Laravel\postJson;

use Tests\Support\CatalogFactory;
use Tests\Support\TenantFactory;
use Tests\Support\UsageStream;

/**
 * Getting set-aside messages back: the operator's side of the dead-letter
 * stream. The consumer's side — what it sets aside and why — is in
 * ConsumerRecoveryTest.
 */
beforeEach(function (): void {
    deadLetterRedis()->command('del', [UsageStream::key()]);
    deadLetterRedis()->command('del', [UsageStream::deadLetter()]);
});

function deadLetterRedis(): PhpRedisConnection
{
    $connection = app(RedisFactory::class)->connection('usage');

    return $connection instanceof PhpRedisConnection
        ? $connection
        : throw new RuntimeException('These tests need the phpredis client.');
}

/**
 * Sends one event through the API and has the consumer give up on it, the
 * way a write that keeps failing ends: set aside as `too_many_deliveries`.
 */
function deadLetterAnEvent(string $eventId = 'evt_1'): TenantContext
{
    $project = TenantFactory::tenant();
    CatalogFactory::meter($project->tenant(), 'api.requests');
    CatalogFactory::customer($project->tenant(), 'cus_4471');
    ['secret' => $secret] = TenantFactory::apiKey($project, [Scope::UsageWrite]);

    postJson('/api/v1/usage/events', ['events' => [[
        'event_id' => $eventId,
        'meter_code' => 'api.requests',
        'customer_ref' => 'cus_4471',
        'quantity' => '2.5',
        'occurred_at' => now()->subMinute()->toAtomString(),
    ]]], ['Authorization' => 'Bearer ' . $secret->reveal()])->assertStatus(202);

    app(StreamConsumer::class)->ensureGroup();
    deadLetterRedis()->command('xreadgroup', [UsageStream::group(), 'consumer-that-died', [UsageStream::key() => '>'], 10]);

    configureDeadLetterConsumer(maxDeliveries: 0);
    consumeDeadLetterTest();
    configureDeadLetterConsumer(maxDeliveries: 5);

    return $project->tenant();
}

function configureDeadLetterConsumer(int $maxDeliveries): void
{
    config([
        'metered.usage.consumer.reclaim_idle_milliseconds' => 0,
        'metered.usage.consumer.max_deliveries' => $maxDeliveries,
    ]);
    app()->forgetInstance(BatchProcessor::class);
    app()->forgetInstance(StreamConsumer::class);
}

function consumeDeadLetterTest(): void
{
    $consumer = app(StreamConsumer::class);
    $consumer->ensureGroup();
    $consumer->consumeOnce('test-consumer');
}

function writtenEvents(TenantContext $tenant): int
{
    return DB::table('usage_events')->where('project_id', $tenant->projectId->value)->count();
}

it('lists dead letters newest first, with why and what they were', function (): void {
    $tenant = deadLetterAnEvent('evt_first');
    deadLetterRedis()->command('xadd', [UsageStream::deadLetter(), '*', [
        'nonsense' => 'entirely',
        DeadLetters::REASON => DeadLetters::MALFORMED,
    ]]);

    $letters = app(DeadLetters::class)->list(10);

    expect($letters)->toHaveCount(2)
        ->and($letters[0]->reason)->toBe('malformed')
        ->and($letters[0]->eventId)->toBeNull()
        ->and($letters[1]->reason)->toBe('too_many_deliveries')
        ->and($letters[1]->eventId)->toBe('evt_first')
        ->and($letters[1]->projectId)->toBe($tenant->projectId->value)
        ->and($letters[1]->meterCode)->toBe('api.requests')
        ->and($letters[1]->customerReference)->toBe('cus_4471')
        ->and($letters[1]->deliveries)->toBe(1)
        ->and($letters[1]->deadLetteredAt)->not->toBeNull();
});

it('lists no more than asked for', function (): void {
    foreach (range(1, 3) as $index) {
        deadLetterRedis()->command('xadd', [UsageStream::deadLetter(), '*', ['n' => (string) $index, DeadLetters::REASON => DeadLetters::MALFORMED]]);
    }

    expect(app(DeadLetters::class)->list(2))->toHaveCount(2);
});

it('moves a replayed message back to the stream, without the fields the consumer added', function (): void {
    deadLetterAnEvent();
    [$letter] = app(DeadLetters::class)->list(1);

    $outcome = app(DeadLetters::class)->replay($letter->id);

    $stream = deadLetterRedis()->command('xrange', [UsageStream::key(), '-', '+']);
    $entries = is_array($stream) ? array_values($stream) : [];
    $replayed = is_array($entries[1] ?? null) ? $entries[1] : [];

    expect($outcome)->toBe(ReplayOutcome::Replayed)
        ->and(app(DeadLetters::class)->list(10))->toBe([])
        ->and($replayed)->toHaveKey('event_id', 'evt_1')
        ->and(array_filter(array_keys($replayed), static fn(int|string $name): bool => str_starts_with((string) $name, '_')))->toBe([]);
});

it('writes a replayed event exactly once, however often it is replayed', function (): void {
    $tenant = deadLetterAnEvent();
    [$letter] = app(DeadLetters::class)->list(1);
    $fields = deadLetterRedis()->command('xrange', [UsageStream::deadLetter(), $letter->id, $letter->id]);

    expect(writtenEvents($tenant))->toBe(0);

    app(DeadLetters::class)->replay($letter->id);
    consumeDeadLetterTest();

    // The same message set aside and replayed a second time, as happens when
    // an operator replays from an older copy of the list.
    $copy = deadLetterRedis()->command('xadd', [UsageStream::deadLetter(), '*', is_array($fields) ? array_values($fields)[0] : []]);
    app(DeadLetters::class)->replay(is_string($copy) ? $copy : '');
    consumeDeadLetterTest();

    expect(writtenEvents($tenant))->toBe(1);
});

it('refuses a malformed message and leaves it where it is', function (): void {
    $id = deadLetterRedis()->command('xadd', [UsageStream::deadLetter(), '*', [
        'nonsense' => 'entirely',
        DeadLetters::REASON => DeadLetters::MALFORMED,
    ]]);

    expect(app(DeadLetters::class)->replay(is_string($id) ? $id : ''))->toBe(ReplayOutcome::Malformed)
        ->and(app(DeadLetters::class)->list(10))->toHaveCount(1)
        ->and(deadLetterRedis()->command('xlen', [UsageStream::key()]))->toBe(0);
});

it('says so when an id is not in the dead-letter stream', function (): void {
    expect(app(DeadLetters::class)->replay('1-0'))->toBe(ReplayOutcome::NotFound);
});

it('replays every replayable entry with --all, and fails on the one it refused', function (): void {
    $tenant = deadLetterAnEvent();
    deadLetterRedis()->command('xadd', [UsageStream::deadLetter(), '*', [
        'nonsense' => 'entirely',
        DeadLetters::REASON => DeadLetters::MALFORMED,
    ]]);

    expect(Artisan::call('usage:dead-letters:replay', ['--all' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('1 replayed, 0 not found, 1 refused as malformed, 0 refused because the project is gone.');

    consumeDeadLetterTest();

    expect(writtenEvents($tenant))->toBe(1)
        ->and(app(DeadLetters::class)->list(10))->toHaveCount(1);
});

it('replays by id and succeeds when every id went back', function (): void {
    deadLetterAnEvent();
    [$letter] = app(DeadLetters::class)->list(1);

    expect(Artisan::call('usage:dead-letters:replay', ['ids' => [$letter->id]]))->toBe(0)
        ->and(Artisan::output())->toContain($letter->id . '  replayed');
});

it('fails on an id it cannot find', function (): void {
    expect(Artisan::call('usage:dead-letters:replay', ['ids' => ['1-0']]))->toBe(1)
        ->and(Artisan::output())->toContain('1-0  not found');
});

it('wants either ids or --all, not both and not neither', function (): void {
    expect(Artisan::call('usage:dead-letters:replay'))->toBe(1)
        ->and(Artisan::call('usage:dead-letters:replay', ['ids' => ['1-0'], '--all' => true]))->toBe(1);
});

it('shows the dead letters as a table, and says when there are none', function (): void {
    expect(Artisan::call('usage:dead-letters'))->toBe(0)
        ->and(Artisan::output())->toContain('The dead-letter stream is empty.');

    deadLetterAnEvent('evt_listed');

    expect(Artisan::call('usage:dead-letters'))->toBe(0)
        ->and(Artisan::output())->toContain('evt_listed');
});

it('refuses a limit that is not a positive number', function (): void {
    expect(Artisan::call('usage:dead-letters', ['--limit' => '0']))->toBe(1)
        ->and(Artisan::call('usage:dead-letters', ['--limit' => 'ten']))->toBe(1);
});

it('refuses a message whose project was deleted, and leaves it where it is', function (): void {
    $id = deadLetterRedis()->command('xadd', [UsageStream::deadLetter(), '*', [
        'event_id' => 'evt_orphan',
        DeadLetters::REASON => DeadLetters::PROJECT_GONE,
    ]]);

    expect(app(DeadLetters::class)->replay(is_string($id) ? $id : ''))->toBe(ReplayOutcome::ProjectGone)
        ->and(app(DeadLetters::class)->list(10))->toHaveCount(1)
        ->and(deadLetterRedis()->command('xlen', [UsageStream::key()]))->toBe(0);
});
