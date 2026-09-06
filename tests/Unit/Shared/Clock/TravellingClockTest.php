<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Metered\Shared\Infrastructure\Clock\ClockOffset;
use Metered\Shared\Infrastructure\Clock\TravellingClock;
use Symfony\Component\Clock\MockClock;

it('is real time until the offset moves it, and then real time plus the offset', function (): void {
    $offset = new ClockOffset(new Repository(new ArrayStore()));
    $tick = 0;
    $clock = new TravellingClock(new MockClock('2026-03-15 12:00:00', 'UTC'), $offset, static function () use (&$tick): int {
        return $tick;
    });

    expect($clock->now()->format(DATE_ATOM))->toBe('2026-03-15T12:00:00+00:00');

    $offset->set(31 * 86_400);

    // Read at most once a second: a jump is seen within a second, not at once.
    $tick = 999_999_999;
    expect($clock->now()->format(DATE_ATOM))->toBe('2026-03-15T12:00:00+00:00');

    $tick = 1_000_000_000;
    expect($clock->now()->format(DATE_ATOM))->toBe('2026-04-15T12:00:00+00:00');

    $offset->set(0);
    $tick = 2_000_000_000;
    expect($clock->now()->format(DATE_ATOM))->toBe('2026-03-15T12:00:00+00:00')
        ->and($offset->seconds())->toBe(0);
});

it('reads the offset back as whole seconds, however the store kept them', function (mixed $stored, int $seconds): void {
    $cache = new Repository(new ArrayStore());
    $cache->forever('metered:clock:offset', $stored);

    expect(new ClockOffset($cache)->seconds())->toBe($seconds);
})->with([
    'an int' => [7200, 7200],
    // How the Redis store returns it: numbers are stored unserialised.
    'a numeric string' => ['7200', 7200],
    'nonsense' => ['three days', 0],
    'a fraction' => ['1.5', 0],
]);
