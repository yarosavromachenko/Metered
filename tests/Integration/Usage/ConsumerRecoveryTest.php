<?php

declare(strict_types=1);

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\DB;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Domain\Scope;
use Metered\Usage\Application\Ingestion\BatchProcessor;
use Metered\Usage\Application\Ingestion\Deduplicator;
use Metered\Usage\Application\Ingestion\EventWriter;
use Metered\Usage\Application\Ingestion\IncomingEvent;
use Metered\Usage\Application\Ingestion\WriteOutcome;
use Metered\Usage\Infrastructure\Persistence\DatabaseEventWriter;
use Metered\Usage\Infrastructure\Persistence\UsageReconciler;
use Metered\Usage\Infrastructure\Redis\StreamConsumer;
use Metered\Usage\Infrastructure\Redis\StreamEnvelope;

use function Pest\Laravel\postJson;

use Psr\Clock\ClockInterface;
use Tests\Support\CatalogFactory;
use Tests\Support\TenantFactory;

/**
 * What happens when something goes wrong: a message nobody can read, a
 * message that keeps failing, a consumer that died holding work, and a write
 * that did not commit.
 *
 * These are the tests the design exists for. A batch that succeeds proves
 * plumbing; a batch that fails proves the guarantees.
 */
const RECOVERY_STREAM = 'usage:events';

const RECOVERY_DLQ = 'usage:events:dead';

const RECOVERY_GROUP = 'usage-writers';

beforeEach(function (): void {
    usageRedis()->command('del', [RECOVERY_STREAM]);
    usageRedis()->command('del', [RECOVERY_DLQ]);

    $claims = usageRedis()->command('keys', ['usage:dedup:*']);

    foreach (is_array($claims) ? $claims : [] as $key) {
        usageRedis()->command('del', [$key]);
    }
});

function usageRedis(): PhpRedisConnection
{
    $connection = app(RedisFactory::class)->connection('usage');

    return $connection instanceof PhpRedisConnection
        ? $connection
        : throw new RuntimeException('These tests need the phpredis client.');
}

/**
 * @return array{tenant: TenantContext, headers: array<string, string>}
 */
function recoveryTenant(): array
{
    $project = TenantFactory::tenant();
    CatalogFactory::meter($project->tenant(), 'api.requests');
    CatalogFactory::customer($project->tenant(), 'cus_4471');
    ['secret' => $secret] = TenantFactory::apiKey($project, [Scope::UsageWrite]);

    return [
        'tenant' => $project->tenant(),
        'headers' => ['Authorization' => 'Bearer ' . $secret->reveal()],
    ];
}

/**
 * @param  array<string, string>  $headers
 * @param  array<string, mixed>  $overrides
 */
function post(array $headers, array $overrides = []): void
{
    postJson('/api/v1/usage/events', ['events' => [[
        'event_id' => 'evt_1',
        'meter_code' => 'api.requests',
        'customer_ref' => 'cus_4471',
        'quantity' => '2.5',
        'occurred_at' => now()->subMinute()->toAtomString(),
        ...$overrides,
    ]]], $headers)->assertStatus(202);
}

function runConsumer(string $name = 'test-consumer'): void
{
    $consumer = app(StreamConsumer::class);
    $consumer->ensureGroup();
    $consumer->consumeOnce($name);
}

/**
 * @return list<array<string, string>>
 */
function deadLettered(): array
{
    $entries = usageRedis()->command('xrange', [RECOVERY_DLQ, '-', '+']);
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

it('dead-letters a message it cannot read, and tells the tenant why', function (): void {
    ['tenant' => $tenant] = recoveryTenant();

    // A message from a version this consumer does not know: exactly what a
    // half-finished deploy produces, and the one case where guessing would be
    // worse than refusing.
    usageRedis()->command('xadd', [RECOVERY_STREAM, '*', [
        'v' => '99',
        'organization_id' => $tenant->organizationId->value,
        'project_id' => $tenant->projectId->value,
        'event_id' => 'evt_from_the_future',
    ]]);

    runConsumer();

    $rejection = DB::table('usage_event_rejections')->where('project_id', $tenant->projectId->value)->first();

    expect(deadLettered())->toHaveCount(1)
        ->and(deadLettered()[0]['_reason'])->toBe('malformed')
        ->and($rejection?->reason)->toBe('malformed')
        ->and($rejection?->event_id)->toBe('evt_from_the_future');
});

it('dead-letters an unreadable message even when it cannot say whose it is', function (): void {
    usageRedis()->command('xadd', [RECOVERY_STREAM, '*', ['nonsense' => 'entirely']]);

    runConsumer();

    // No project, so no rejection row is possible: the dead-letter entry is
    // the only record there can be, which is why it keeps the message.
    expect(deadLettered())->toHaveCount(1)
        ->and(DB::table('usage_event_rejections')->count())->toBe(0);
});

it('acknowledges what it dead-letters, so it is not handed the same poison forever', function (): void {
    usageRedis()->command('xadd', [RECOVERY_STREAM, '*', ['nonsense' => 'entirely']]);

    runConsumer();
    runConsumer();

    $pending = usageRedis()->command('xpending', [RECOVERY_STREAM, RECOVERY_GROUP]);

    expect(deadLettered())->toHaveCount(1)
        ->and(is_array($pending) ? $pending[0] : 0)->toBe(0);
});

it('takes over the work of a consumer that stopped acknowledging', function (): void {
    ['tenant' => $tenant, 'headers' => $headers] = recoveryTenant();
    post($headers);

    app(StreamConsumer::class)->ensureGroup();

    // A consumer reads a batch and dies before writing anything: the message
    // stays pending, owned by a name that will never come back.
    usageRedis()->command('xreadgroup', [RECOVERY_GROUP, 'consumer-that-died', [RECOVERY_STREAM => '>'], 10]);

    expect(DB::table('usage_events')->where('project_id', $tenant->projectId->value)->count())->toBe(0);

    // Anything idle at all is fair game for this test; in production it is a
    // minute, which is the difference between a dead worker and a slow one.
    config(['metered.usage.consumer.reclaim_idle_milliseconds' => 0]);
    app()->forgetInstance(BatchProcessor::class);
    app()->forgetInstance(StreamConsumer::class);

    runConsumer('consumer-that-lived');

    expect(DB::table('usage_events')->where('project_id', $tenant->projectId->value)->count())->toBe(1);
});

it('sets aside a message that has been delivered too many times', function (): void {
    ['tenant' => $tenant, 'headers' => $headers] = recoveryTenant();
    post($headers);

    app(StreamConsumer::class)->ensureGroup();
    usageRedis()->command('xreadgroup', [RECOVERY_GROUP, 'consumer-that-died', [RECOVERY_STREAM => '>'], 10]);

    // One delivery already happened, and this consumer is configured to allow
    // none: the next reclaim is the last straw. In production the number is
    // five, and the point is the same — one poison message must not hold a
    // tenant's ingestion behind it.
    config([
        'metered.usage.consumer.reclaim_idle_milliseconds' => 0,
        'metered.usage.consumer.max_deliveries' => 0,
    ]);
    app()->forgetInstance(BatchProcessor::class);
    app()->forgetInstance(StreamConsumer::class);

    runConsumer();

    expect(deadLettered())->toHaveCount(1)
        ->and(deadLettered()[0]['_reason'])->toBe('too_many_deliveries')
        ->and(deadLettered()[0]['event_id'])->toBe('evt_1')
        ->and(DB::table('usage_events')->where('project_id', $tenant->projectId->value)->count())->toBe(0);
});

it('keeps the claim of a write that did not happen, stamped with the event’s own time', function (): void {
    ['tenant' => $tenant, 'headers' => $headers] = recoveryTenant();
    $occurredAt = now()->subMinute()->startOfSecond();
    post($headers, ['occurred_at' => $occurredAt->toAtomString()]);

    // The transaction fails after the claim was taken. The claim stays, and
    // holds the timestamp it was taken for: that is what lets the redelivery
    // through to the database instead of being dismissed as a duplicate.
    app()->bind(EventWriter::class, fn(): EventWriter => new class implements EventWriter {
        public function write(TenantContext $tenant, array $events): WriteOutcome
        {
            throw new RuntimeException('The database went away mid-batch.');
        }
    });
    app()->forgetInstance(BatchProcessor::class);
    app()->forgetInstance(StreamConsumer::class);

    expect(static function (): void {
        runConsumer();
    })->toThrow(RuntimeException::class, 'went away');

    $claim = usageRedis()->command('get', ['usage:dedup:' . $tenant->projectId->value . ':evt_1']);

    expect($claim)->toBe($occurredAt->format('U.u'));
});

it('writes the event on the retry that follows a failed write', function (): void {
    ['tenant' => $tenant, 'headers' => $headers] = recoveryTenant();
    post($headers);

    app()->bind(EventWriter::class, fn(): EventWriter => new class implements EventWriter {
        public function write(TenantContext $tenant, array $events): WriteOutcome
        {
            throw new RuntimeException('The database went away mid-batch.');
        }
    });
    app()->forgetInstance(BatchProcessor::class);
    app()->forgetInstance(StreamConsumer::class);

    try {
        runConsumer();
    } catch (RuntimeException) {
        // Expected: nothing was acknowledged, so the message is still pending.
    }

    // The real writer is back, and the message is reclaimed as it would be
    // after a restart.
    app()->bind(EventWriter::class, static fn(): EventWriter => new DatabaseEventWriter(
        app(DatabaseManager::class),
        testConnection(),
        app(ClockInterface::class),
    ));
    config(['metered.usage.consumer.reclaim_idle_milliseconds' => 0]);
    app()->forgetInstance(BatchProcessor::class);
    app()->forgetInstance(StreamConsumer::class);

    runConsumer();

    expect(DB::table('usage_events')->where('project_id', $tenant->projectId->value)->count())->toBe(1);
});

it('leaves no drift when a consumer dies between the commit and the acknowledgement', function (): void {
    ['tenant' => $tenant, 'headers' => $headers] = recoveryTenant();

    post($headers, ['event_id' => 'evt_1', 'quantity' => '2.5']);
    post($headers, ['event_id' => 'evt_2', 'quantity' => '1.5']);

    app(StreamConsumer::class)->ensureGroup();

    // The batch is delivered and written, and then the process dies — the
    // window between the commit and the XACK, which is the one a crash is
    // most likely to land in because it is the one that involves a network
    // call after a transaction.
    $delivered = usageRedis()->command('xreadgroup', [
        RECOVERY_GROUP,
        'consumer-that-died',
        [RECOVERY_STREAM => '>'],
        10,
    ]);

    $incoming = [];
    $messages = is_array($delivered) && isset($delivered[RECOVERY_STREAM]) && is_array($delivered[RECOVERY_STREAM])
        ? $delivered[RECOVERY_STREAM]
        : [];

    foreach ($messages as $fields) {
        $message = [];

        foreach (is_array($fields) ? $fields : [] as $name => $value) {
            $message[(string) $name] = is_scalar($value) ? (string) $value : '';
        }

        $envelope = StreamEnvelope::decode($message);

        if ($envelope instanceof StreamEnvelope) {
            $incoming[] = new IncomingEvent($envelope->event, $envelope->receivedAt);
        }
    }

    app(BatchProcessor::class)->process($tenant, $incoming);

    expect(DB::table('usage_events')->where('project_id', $tenant->projectId->value)->count())->toBe(2);

    // Restart: the unacknowledged messages are reclaimed and handed over
    // again, exactly as they would be to a new container.
    config(['metered.usage.consumer.reclaim_idle_milliseconds' => 0]);
    app()->forgetInstance(BatchProcessor::class);
    app()->forgetInstance(StreamConsumer::class);

    runConsumer('consumer-that-lived');

    $drift = app(UsageReconciler::class)->check(
        $tenant,
        new DateTimeImmutable('-1 day'),
        new DateTimeImmutable('+1 hour'),
    );

    // The redelivery inserted nothing, so it folded nothing: the aggregate
    // still equals the events under it (ADR-0004).
    expect(DB::table('usage_events')->where('project_id', $tenant->projectId->value)->count())->toBe(2)
        ->and(DB::table('usage_aggregates')->where('project_id', $tenant->projectId->value)->value('quantity'))
        ->toBe('4.000000')
        ->and($drift)->toBe([]);
});

it('loses nothing when a consumer dies between the claim and the commit', function (): void {
    ['tenant' => $tenant, 'headers' => $headers] = recoveryTenant();
    post($headers, ['event_id' => 'evt_1', 'quantity' => '2.5']);

    // The events are claimed in Redis, and the process dies before the
    // transaction commits. Nothing runs afterwards to give the claims back:
    // this is SIGKILL, an OOM, a host going away, not an exception the
    // processor gets to catch. So the claims stay, and the write never lands.
    $real = app(Deduplicator::class);
    app()->bind(Deduplicator::class, static fn(): Deduplicator => new readonly class ($real) implements Deduplicator {
        public function __construct(private Deduplicator $real) {}

        public function claim(TenantContext $tenant, array $events): array
        {
            return $this->real->claim($tenant, $events);
        }
    });
    app()->bind(EventWriter::class, fn(): EventWriter => new class implements EventWriter {
        public function write(TenantContext $tenant, array $events): WriteOutcome
        {
            throw new RuntimeException('Killed before the commit.');
        }
    });
    app()->forgetInstance(BatchProcessor::class);
    app()->forgetInstance(StreamConsumer::class);

    try {
        runConsumer('consumer-that-died');
    } catch (RuntimeException) {
        // The process is gone; the message is pending and the claim is held.
    }

    // Restart: the message is reclaimed, and the claim the dead consumer left
    // behind must not be mistaken for the event having been counted.
    app()->bind(Deduplicator::class, static fn(): Deduplicator => $real);
    app()->bind(EventWriter::class, static fn(): EventWriter => new DatabaseEventWriter(
        app(DatabaseManager::class),
        testConnection(),
        app(ClockInterface::class),
    ));
    config(['metered.usage.consumer.reclaim_idle_milliseconds' => 0]);
    app()->forgetInstance(BatchProcessor::class);
    app()->forgetInstance(StreamConsumer::class);

    runConsumer('consumer-that-lived');

    expect(DB::table('usage_events')->where('project_id', $tenant->projectId->value)->count())->toBe(1)
        ->and(DB::table('usage_aggregates')->where('project_id', $tenant->projectId->value)->value('quantity'))
        ->toBe('2.500000');
});
