<?php

declare(strict_types=1);

use Metered\Shared\Infrastructure\Clock\SystemClock;

it('always reports UTC, whatever the host is set to', function (): void {
    $previous = date_default_timezone_get();
    date_default_timezone_set('Pacific/Kiritimati');

    try {
        expect(new SystemClock()->now()->getTimezone()->getName())->toBe('UTC');
    } finally {
        date_default_timezone_set($previous);
    }
});

it('returns an immutable instant', function (): void {
    $clock = new SystemClock();
    $first = $clock->now();
    $second = $first->modify('+1 day');

    expect($second)->not->toBe($first)
        ->and($first->getTimestamp())->toBeLessThan($second->getTimestamp());
});

it('never goes backwards between two readings', function (): void {
    $clock = new SystemClock();

    expect($clock->now()->getTimestamp())->toBeLessThanOrEqual($clock->now()->getTimestamp());
});
