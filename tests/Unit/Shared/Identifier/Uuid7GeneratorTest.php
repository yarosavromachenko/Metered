<?php

declare(strict_types=1);

use Metered\Shared\Infrastructure\Identifier\Uuid7Generator;
use Symfony\Component\Clock\MockClock;

it('generates version 7 identifiers', function (): void {
    $generator = new Uuid7Generator(new MockClock('2026-09-05 11:00:00', 'UTC'));

    expect($generator->generate()->version())->toBe(7);
});

it('generates identifiers that sort in the order they were created', function (): void {
    $clock = new MockClock('2026-09-05 11:00:00', 'UTC');
    $generator = new Uuid7Generator($clock);

    $ids = [];

    for ($i = 0; $i < 25; $i++) {
        $ids[] = $generator->generate()->value;
        $clock->sleep(0.005);
    }

    $sorted = $ids;
    sort($sorted);

    // This is the whole reason for choosing v7: ids created over time are
    // already in index order, so inserts stay at the right edge of the B-tree.
    expect($ids)->toBe($sorted);
});

it('encodes the clock it was given rather than the system time', function (): void {
    $generator = new Uuid7Generator(new MockClock('2001-09-09 01:46:40', 'UTC'));

    // The first 48 bits of a v7 identifier are the millisecond timestamp,
    // and 2001-09-09T01:46:40Z is exactly 1_000_000_000_000 milliseconds.
    $hex = str_replace('-', '', $generator->generate()->value);

    expect(hexdec(substr($hex, 0, 12)))->toBe(1_000_000_000_000);
});

it('does not repeat itself within a single millisecond', function (): void {
    $generator = new Uuid7Generator(new MockClock('2026-09-05 11:00:00', 'UTC'));

    $ids = [];

    for ($i = 0; $i < 500; $i++) {
        $ids[] = $generator->generate()->value;
    }

    expect(array_unique($ids))->toHaveCount(500);
});
