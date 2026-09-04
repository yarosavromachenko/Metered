<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Metered\Usage\Infrastructure\Persistence\Partition;
use Metered\Usage\Infrastructure\Persistence\PartitionManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

const PARTITION_PROJECT_ID = '01924b7c-0000-7000-8000-000000000301';

/**
 * Partitions are schema, so these tests create and drop real ones rather than
 * asserting on generated SQL. They clean up after themselves for the same
 * reason: DDL is not rolled back by the wrapping transaction the other
 * integration tests rely on.
 */
/**
 * @return list<string>
 */
function partitionNames(): array
{
    return array_map(
        static fn(Partition $partition): string => $partition->name,
        app(PartitionManager::class)->partitions(),
    );
}

function dropPartitions(string ...$names): void
{
    foreach ($names as $name) {
        DB::statement(sprintf('DROP TABLE IF EXISTS %s', $name));
    }
}

it('creates a partition for every day in the window, and says which', function (): void {
    $around = new DateTimeImmutable('2019-05-04T12:00:00+00:00');

    $created = app(PartitionManager::class)->ensure($around, daysBack: 1, daysAhead: 1);

    expect($created)->toBe(['usage_events_p20190503', 'usage_events_p20190504', 'usage_events_p20190505'])
        ->and(partitionNames())->toContain('usage_events_p20190504');

    dropPartitions(...$created);
});

it('reaches backwards as far as the acceptance window does', function (): void {
    // An event from six days ago is legitimate. If only future partitions
    // existed, every one of them would land in the default partition.
    $created = app(PartitionManager::class)
        ->ensure(new DateTimeImmutable('2019-06-10T00:00:00+00:00'), daysBack: 7, daysAhead: 0);

    expect($created)->toHaveCount(8)
        ->and($created[0])->toBe('usage_events_p20190603');

    dropPartitions(...$created);
});

it('is a no-op the second time it runs', function (): void {
    $around = new DateTimeImmutable('2019-07-01T09:00:00+00:00');
    $manager = app(PartitionManager::class);

    $first = $manager->ensure($around, daysBack: 0, daysAhead: 2);
    $second = $manager->ensure($around, daysBack: 0, daysAhead: 2);

    expect($first)->toHaveCount(3)
        ->and($second)->toBe([]);

    dropPartitions(...$first);
});

it('puts an event in the partition its own timestamp names', function (): void {
    $created = app(PartitionManager::class)
        ->ensure(new DateTimeImmutable('2019-08-15T00:00:00+00:00'), daysBack: 0, daysAhead: 0);

    DB::table('usage_events')->insert(usageRow('2019-08-15T23:59:59+00:00'));
    DB::table('usage_events')->insert(usageRow('2019-08-16T00:00:00+00:00', 'evt_next_day'));

    // The first lands in the day's partition; the second has nowhere to go but
    // the default, which is exactly what the default is for.
    expect(DB::table('usage_events_p20190815')->count())->toBe(1)
        ->and(DB::table('usage_events_default')->where('event_id', 'evt_next_day')->count())->toBe(1);

    DB::table('usage_events')->where('project_id', PARTITION_PROJECT_ID)->delete();
    dropPartitions(...$created);
});

it('reports rows that fell into the default partition, because that is the failure', function (): void {
    DB::table('usage_events')->insert(usageRow('2019-09-09T10:00:00+00:00', 'evt_orphan'));

    expect(app(PartitionManager::class)->health()['default_rows'])->toBe(1);

    DB::table('usage_events')->where('project_id', PARTITION_PROJECT_ID)->delete();
});

it('refuses to carve a day out from under rows already in the default partition', function (): void {
    DB::table('usage_events')->insert(usageRow('2019-10-10T10:00:00+00:00', 'evt_stranded'));

    // PostgreSQL will not create a range that the default partition already
    // holds rows for, and the message has to say what to do about it rather
    // than repeat the constraint name.
    expect(static fn(): array => app(PartitionManager::class)
        ->ensure(new DateTimeImmutable('2019-10-10T00:00:00+00:00'), daysBack: 0, daysAhead: 0))
        ->toThrow(RuntimeException::class, 'the default partition already holds rows');

    // No cleanup here on purpose: the failed CREATE aborted the surrounding
    // transaction, so the only statement PostgreSQL will still accept is the
    // rollback the test case issues anyway.
});

it('drops only the partitions entirely past retention', function (): void {
    $manager = app(PartitionManager::class);
    $created = $manager->ensure(new DateTimeImmutable('2019-11-20T00:00:00+00:00'), daysBack: 2, daysAhead: 0);

    // Retention cuts at the start of the 19th: the 18th is wholly behind it,
    // the 19th is not, and a partition half past retention is not past it.
    $dropped = $manager->prune(new DateTimeImmutable('2019-11-19T00:00:00+00:00'));

    expect($dropped)->toBe(['usage_events_p20191118'])
        ->and(partitionNames())->toContain('usage_events_p20191119');

    dropPartitions(...$created);
});

it('never drops the default partition, whatever the retention', function (): void {
    app(PartitionManager::class)->prune(new DateTimeImmutable('2030-01-01T00:00:00+00:00'));

    expect(partitionNames())->toContain('usage_events_default');
});

it('creates the window the scheduler asks for and reports what it holds', function (): void {
    $status = Artisan::call('usage:partitions:ensure', ['--days' => '1']);

    expect($status)->toBe(0)
        ->and(Artisan::output())->toContain('usage_events has')
        // The default partition is one of them, and it is never the whole
        // story: a working installation has a partition per day as well.
        ->and(partitionNames())->toContain('usage_events_default');
});

it('reaches further back when history is to be loaded', function (): void {
    app()->instance(ClockInterface::class, new MockClock('2019-09-30 12:00:00', 'UTC'));

    expect(Artisan::call('usage:partitions:ensure', ['--days' => '0', '--back' => '40']))->toBe(0)
        ->and(partitionNames())->toContain('usage_events_p20190821')
        ->and(partitionNames())->not->toContain('usage_events_p20190820');

    dropPartitions(...array_values(array_filter(partitionNames(), static fn(string $name): bool => str_starts_with($name, 'usage_events_p2019'))));
});

/**
 * @return array<string, string>
 */
function usageRow(string $occurredAt, string $eventId = 'evt_1'): array
{
    return [
        'id' => '01924b7c-0000-7000-8000-000000000302',
        'organization_id' => '01924b7c-0000-7000-8000-000000000303',
        'project_id' => PARTITION_PROJECT_ID,
        'event_id' => $eventId,
        'customer_id' => '01924b7c-0000-7000-8000-000000000304',
        'meter_id' => '01924b7c-0000-7000-8000-000000000305',
        'meter_code' => 'api.requests',
        'customer_ref' => 'cus_4471',
        'quantity' => '1.000000',
        'occurred_at' => $occurredAt,
        'received_at' => $occurredAt,
        'properties' => '{}',
    ];
}

it('arrives with a fortnight of partitions, so the first event has a home', function (): void {
    // The scheduler has never run on a fresh installation, and the first
    // events usually arrive before it does. Without partitions around today
    // they would land in the default one, and the command could no longer
    // carve today out from under them.
    $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));

    expect(partitionNames())->toContain('usage_events_p' . $today->format('Ymd'))
        ->and(partitionNames())->toContain(
            'usage_events_p' . $today->sub(new DateInterval('P7D'))->format('Ymd'),
        );
});

it('moves rows stranded in the default partition into the day they belong to', function (): void {
    DB::table('usage_events')->insert(usageRow('2019-12-24T10:00:00+00:00', 'evt_stranded'));

    $created = app(PartitionManager::class)->ensure(
        new DateTimeImmutable('2019-12-24T00:00:00+00:00'),
        daysBack: 0,
        daysAhead: 0,
        rescueStrandedRows: true,
    );

    expect($created)->toBe(['usage_events_p20191224'])
        ->and(DB::table('usage_events_p20191224')->where('event_id', 'evt_stranded')->count())->toBe(1)
        ->and(DB::table('usage_events_default')->where('event_id', 'evt_stranded')->count())->toBe(0)
        // The row is still one row of the parent table: nothing was copied
        // into two places.
        ->and(DB::table('usage_events')->where('event_id', 'evt_stranded')->count())->toBe(1);

    DB::table('usage_events')->where('event_id', 'evt_stranded')->delete();
    dropPartitions(...$created);
});

it('names the flag that fixes stranded rows rather than only refusing', function (): void {
    DB::table('usage_events')->insert(usageRow('2019-12-25T10:00:00+00:00', 'evt_stranded'));

    expect(static fn(): array => app(PartitionManager::class)
        ->ensure(new DateTimeImmutable('2019-12-25T00:00:00+00:00'), daysBack: 0, daysAhead: 0))
        ->toThrow(RuntimeException::class, '--rescue');
});
