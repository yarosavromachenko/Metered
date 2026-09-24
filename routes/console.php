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
| Every overlap lock is given a lifetime near its command's own interval.
| The default is a day, and a scheduler restarted while a command holds its
| lock would leave that command skipped for the rest of it — for webhook
| delivery, that is every webhook stopping.
|
*/

/*
|--------------------------------------------------------------------------
| Partitions
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
    ->withoutOverlapping(60)
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
    ->withoutOverlapping(5)
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
    ->withoutOverlapping(1)
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Demo tenants
|--------------------------------------------------------------------------
|
| A demo tenant nobody has signed in to for a week is deleted, so an instance
| that strangers sign up to does not grow without end (ADR-0016). Nothing
| created with org:create is a demo, so on any other installation this finds
| nothing to do.
|
*/

Schedule::command('tenancy:purge-idle-demos')
    ->dailyAt('03:40')
    ->withoutOverlapping(60)
    ->onOneServer();
