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
use Spatie\Fork\Fork;
use Tests\Support\RecordingOutboxPublisher;

$aggregateType = 'concurrency-relay';

afterEach(function () use ($aggregateType): void {
    DB::table('outbox_messages')->where('aggregate_type', $aggregateType)->delete();
});

it('never lets two of three relays publish the same message', function () use ($aggregateType): void {
    $ids = app(IdentifierGenerator::class);
    $writer = app(OutboxWriter::class);
    $clock = app(ClockInterface::class);

    foreach (range(1, 40) as $i) {
        $writer->append(new OutboxMessage(
            id: $ids->generate(),
            aggregateType: $aggregateType,
            aggregateId: $ids->generate(),
            type: 'event.' . $i,
            payload: [],
            headers: [],
            occurredAt: $clock->now(),
        ));
    }

    $relay = static function () use ($aggregateType): array {
        // Separate process, separate connection: with a shared one, SKIP
        // LOCKED would have nothing to skip.
        DB::purge(testConnection());

        $publisher = new RecordingOutboxPublisher();

        $relay = new OutboxRelay(
            app(DatabaseManager::class),
            $publisher,
            app(ClockInterface::class),
            new NullLogger(),
            testConnection(),
            10,
        );

        // Small batches until nothing is left, so the three relays genuinely
        // interleave rather than one finishing before the others start.
        while ($relay->relayBatch(10) > 0) {
        }

        // Only this test's messages are counted. Under --parallel the other
        // concurrency tests share this database and their events may be
        // relayed here too; they are not what this test is about.
        return array_values(array_map(
            static fn(OutboxMessage $m): string => $m->id->value,
            array_filter($publisher->published, static fn(OutboxMessage $m): bool => $m->aggregateType === $aggregateType),
        ));
    };

    /** @var list<list<string>> $results */
    $results = Fork::new()->run($relay, $relay, $relay);

    $published = array_merge(...$results);

    expect($published)->toHaveCount(40)
        // The real assertion: forty publications, forty distinct messages. A
        // duplicate here would mean two relays claimed the same row.
        ->and(array_unique($published))->toHaveCount(40)
        ->and(DB::table('outbox_messages')
            ->where('aggregate_type', $aggregateType)
            ->whereNull('published_at')
            ->count())->toBe(0);
});
