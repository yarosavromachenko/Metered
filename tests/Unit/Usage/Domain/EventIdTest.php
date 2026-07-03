<?php

declare(strict_types=1);

use Metered\Usage\Domain\EventId;
use Metered\Usage\Domain\Exception\InvalidEventId;

it('takes the identifier the client made up for this event', function (string $given): void {
    expect((string) EventId::fromString($given))->toBe($given);
})->with([
    'a uuid' => ['018f3a2e-5b40-7c8e-8a01-2b9c5d6e7f80'],
    'a ulid' => ['01J8ZQ9K7YB2C3D4E5F6G7H8J9'],
    'a hash' => ['e3b0c44298fc1c149afbf4c8996fb924'],
    'their own scheme' => ['req-2026-09-22-4471'],
    'a single character' => ['7'],
]);

it('keeps the case it was given, because it is the client’s own key', function (): void {
    expect((string) EventId::fromString('Evt_AbC'))->toBe('Evt_AbC')
        ->and(EventId::fromString('Evt_AbC')->equals(EventId::fromString('evt_abc')))->toBeFalse();
});

it('trims what a copy-paste leaves behind', function (): void {
    expect((string) EventId::fromString("  evt_1\n"))->toBe('evt_1');
});

it('refuses what could not be compared or logged safely', function (string $given): void {
    expect(static fn(): EventId => EventId::fromString($given))->toThrow(InvalidEventId::class);
})->with([
    'empty' => [''],
    'whitespace only' => ["  \t"],
    'an inner space' => ['evt 1'],
    'a newline inside' => ["evt\n1"],
    'a control character' => ["evt\x001"],
]);

it('refuses an id longer than the column that stores it', function (): void {
    // The column is part of a unique index on a table with hundreds of
    // millions of rows; an unbounded key there is an unbounded index.
    expect(static fn(): EventId => EventId::fromString(str_repeat('e', 129)))
        ->toThrow(InvalidEventId::class, 'at most 128');
});
