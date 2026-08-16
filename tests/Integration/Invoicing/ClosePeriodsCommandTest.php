<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Metered\Invoicing\Application\Command\CloseSubscriptionPeriodsHandler;
use Metered\Invoicing\Infrastructure\Queue\CloseSubscriptionPeriodsJob;
use Tests\Support\InvoicingScenario;

it('queues a close only for subscriptions with a period past its grace window', function (): void {
    Queue::fake();
    // The later scenario binds its clock last, so it is the one the command reads.
    InvoicingScenario::start('2026-02-20 09:00:00', 'north-wind');
    $due = InvoicingScenario::start('2026-01-31 14:00:00', 'acme')->at('2026-02-28 15:00:00');

    expect(Artisan::call('billing:close-periods'))->toBe(0)
        ->and(Artisan::output())->toContain('Queued 1 period close(s).');

    Queue::assertPushedOn('billing', CloseSubscriptionPeriodsJob::class, static fn(CloseSubscriptionPeriodsJob $job): bool => $job->subscriptionId === $due->subscription->id->value
        && $job->projectId === $due->tenant->projectId->value
        && $job->organizationId === $due->tenant->organizationId->value);
    Queue::assertPushed(CloseSubscriptionPeriodsJob::class, 1);
});

it('queues nothing for a subscription whose periods are all invoiced', function (): void {
    $scenario = InvoicingScenario::start()->at('2026-02-28 15:00:00');
    new CloseSubscriptionPeriodsJob($scenario->tenant->organizationId->value, $scenario->tenant->projectId->value, $scenario->subscription->id->value)
        ->handle(app(CloseSubscriptionPeriodsHandler::class));
    Queue::fake();

    expect(Artisan::call('billing:close-periods'))->toBe(0)
        ->and(DB::table('invoices')->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('closes a period when the queued job runs, and keeps two at once from overlapping', function (): void {
    $scenario = InvoicingScenario::start()->at('2026-02-28 15:00:00');
    $job = new CloseSubscriptionPeriodsJob($scenario->tenant->organizationId->value, $scenario->tenant->projectId->value, $scenario->subscription->id->value);

    dispatch_sync($job);

    expect(DB::table('invoices')->where('subscription_id', $scenario->subscription->id->value)->value('number'))->toBe(1)
        ->and($job->middleware())->toHaveCount(1);
});
