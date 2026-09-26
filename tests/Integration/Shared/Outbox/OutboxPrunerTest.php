<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Metered\Shared\Application\Outbox\OutboxWriter;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Outbox\OutboxMessage;
use Metered\Shared\Infrastructure\Outbox\OutboxPruner;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

/**
 * Published outbox messages are kept for the retention window, then removed;
 * unpublished ones are never removed, however old (ADR-0005).
 */
function prunerClock(): MockClock
{
    $clock = new MockClock('2026-09-30 12:00:00', 'UTC');
    app()->instance(ClockInterface::class, $clock);
    app()->forgetInstance(OutboxPruner::class);

    return $clock;
}

function outboxRow(string $type, ?string $publishedAt): string
{
    $ids = app(IdentifierGenerator::class);
    $id = $ids->generate();

    app(OutboxWriter::class)->append(new OutboxMessage(
        id: $id,
        aggregateType: 'invoice',
        aggregateId: $ids->generate(),
        type: $type,
        payload: [],
        headers: [],
        occurredAt: app(ClockInterface::class)->now(),
    ));

    DB::table('outbox_messages')->where('id', $id->value)->update(['published_at' => $publishedAt]);

    return $type;
}

/**
 * @return list<string>
 */
function remainingOutboxTypes(): array
{
    return array_values(array_filter(DB::table('outbox_messages')->orderBy('type')->pluck('type')->all(), is_string(...)));
}

it('removes published messages older than the window and keeps everything else', function (): void {
    prunerClock();
    outboxRow('published.eight-days-ago', '2026-09-22 12:00:00+00');
    outboxRow('published.six-days-ago', '2026-09-24 12:00:00+00');
    outboxRow('unpublished.from-a-month-ago', null);

    $removed = app(OutboxPruner::class)->prune(7);

    expect($removed)->toBe(1)
        ->and(remainingOutboxTypes())->toBe(['published.six-days-ago', 'unpublished.from-a-month-ago']);
});

it('keeps deleting chunk after chunk until nothing old is left', function (): void {
    $clock = prunerClock();

    foreach (range(1, 5) as $index) {
        outboxRow('published.old-' . $index, '2026-09-01 00:00:00+00');
    }

    $pruner = new OutboxPruner(app(DatabaseManager::class), $clock, testConnection(), chunk: 2);

    expect($pruner->prune(7))->toBe(5)
        ->and(remainingOutboxTypes())->toBe([]);
});

it('takes the window from the command line, and refuses one that is not a number of days', function (): void {
    prunerClock();
    outboxRow('published.three-days-ago', '2026-09-27 12:00:00+00');

    expect(Artisan::call('outbox:prune', ['--days' => '2']))->toBe(0)
        ->and(Artisan::output())->toContain('Removed 1 outbox message(s) published more than 2 day(s) ago.')
        ->and(Artisan::call('outbox:prune', ['--days' => '0']))->toBe(1)
        ->and(Artisan::call('outbox:prune', ['--days' => 'a week']))->toBe(1);
});
