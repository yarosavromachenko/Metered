<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Partitions have to exist before the events that belong in them arrive, so
| this runs daily and creates a week ahead: a day of scheduler downtime costs
| pruning, not ingestion, because the default partition still accepts writes
| (ADR-0002).
|
| Without overlapping, because DDL that runs twice at once takes locks on the
| same table for no reason, and on a single server.
|
*/

Schedule::command('usage:partitions:ensure')
    ->dailyAt('03:10')
    ->withoutOverlapping()
    ->onOneServer();
