<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/**
 * The overlap lock outlives a scheduler that dies while holding it. Left at
 * Laravel's default of a day, one restart at the wrong second stopped webhook
 * delivery on a running stack; every lock now lasts about as long as the
 * interval of the command it guards, so a lost one costs a run or two.
 */
it('gives every overlap lock a lifetime near its command\'s own interval', function (string $command, int $minutes): void {
    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        static fn(Event $event): bool => str_contains((string) $event->command, $command),
    ));

    expect($events)->toHaveCount(1)
        ->and($events[0]->withoutOverlapping)->toBeTrue()
        ->and($events[0]->expiresAt)->toBe($minutes);
})->with([
    'webhook dispatch, every ten seconds' => ['webhooks:dispatch', 1],
    'period close, every five minutes' => ['billing:close-periods', 5],
    'partitions, daily' => ['usage:partitions:ensure', 60],
    'demo purge, daily' => ['tenancy:purge-idle-demos', 60],
]);

it('guards nothing it schedules with the default day-long lock', function (): void {
    foreach (app(Schedule::class)->events() as $event) {
        expect($event->expiresAt)->toBeLessThanOrEqual(60, (string) $event->command);
    }
});
