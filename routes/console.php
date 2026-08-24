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

/*
|--------------------------------------------------------------------------
| Period close
|--------------------------------------------------------------------------
|
| Every five minutes, so an invoice is built within minutes of its period's
| grace window passing. The command only queues; the work runs on the billing
| queue, one job per subscription (ADR-0010).
|
*/

Schedule::command('billing:close-periods')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Webhook dispatch
|--------------------------------------------------------------------------
|
| Every ten seconds, so a webhook leaves within seconds of the event that
| caused it, and a retry within seconds of falling due. The command only
| queues; the webhooks queue sends (ADR-0011).
|
*/

Schedule::command('webhooks:dispatch')
    ->everyTenSeconds()
    ->withoutOverlapping()
    ->onOneServer();
