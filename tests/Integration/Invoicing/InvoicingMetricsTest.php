<?php

declare(strict_types=1);

use Metered\Invoicing\Infrastructure\Queue\CloseSubscriptionPeriodsJob;
use Tests\Support\InMemoryMetrics;
use Tests\Support\InvoicingScenario;

/**
 * The billing close's instruments, recorded by the real close.
 */
it('times a close and counts the invoice it finalized', function (): void {
    $metrics = InMemoryMetrics::install();
    $scenario = InvoicingScenario::start()->at('2026-02-28 15:00:00');

    dispatch_sync(new CloseSubscriptionPeriodsJob(
        $scenario->tenant->organizationId->value,
        $scenario->tenant->projectId->value,
        $scenario->subscription->id->value,
    ));

    expect($metrics->counted('invoices.finalized'))->toBe(1)
        ->and($metrics->histogram('billing.close.duration')['count'])->toBe(1);
});

it('counts nothing when there was nothing to finalize', function (): void {
    $metrics = InMemoryMetrics::install();
    // Still inside the first period's grace window: nothing is due yet.
    $scenario = InvoicingScenario::start()->at('2026-02-01 09:00:00');

    dispatch_sync(new CloseSubscriptionPeriodsJob(
        $scenario->tenant->organizationId->value,
        $scenario->tenant->projectId->value,
        $scenario->subscription->id->value,
    ));

    expect($metrics->counted('invoices.finalized'))->toBe(0)
        ->and($metrics->histogram('billing.close.duration')['count'])->toBe(1);
});
