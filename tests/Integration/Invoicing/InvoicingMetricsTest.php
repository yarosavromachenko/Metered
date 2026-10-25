<?php

declare(strict_types=1);

use Metered\Invoicing\Domain\Invoice\BillTo;
use Metered\Invoicing\Domain\Invoice\Invoice;
use Metered\Invoicing\Domain\Invoice\InvoiceLine;
use Metered\Invoicing\Domain\Invoice\InvoicePeriod;
use Metered\Invoicing\Domain\Invoice\InvoiceRepository;
use Metered\Invoicing\Infrastructure\Persistence\DraftGauges;
use Metered\Invoicing\Infrastructure\Queue\CloseSubscriptionPeriodsJob;
use Metered\Shared\Application\Metrics\GaugeReading;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Money\Money;
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

it('counts a draft as overdue once it is older than the grace window', function (): void {
    $scenario = InvoicingScenario::start()->at('2026-02-28 15:00:00');
    $ids = app(IdentifierGenerator::class);
    $period = InvoicePeriod::between(new DateTimeImmutable('2026-01-31T14:00:00Z'), new DateTimeImmutable('2026-02-28T14:00:00Z'));

    // What a close leaves behind when finalizing fails after the draft is committed.
    app(InvoiceRepository::class)->add(Invoice::draft(
        $ids->generate(),
        $scenario->tenant,
        $scenario->customer->id,
        new BillTo('cus_4471', 'North Wind Ltd'),
        $scenario->subscription->id,
        'EUR',
        $period,
        [InvoiceLine::fixed($ids->generate(), 'Flat fee', Money::ofMinorUnits(2900, 'EUR'), $period, [])],
        $scenario->clock->now(),
    ));

    $overdue = static fn(): int => array_map(
        static fn(GaugeReading $reading): int => $reading->value,
        app(DraftGauges::class)->read(),
    )[0];

    expect($overdue())->toBe(0);

    $scenario->clock->modify('+59 minutes');
    expect($overdue())->toBe(0);

    $scenario->clock->modify('+2 minutes');
    expect($overdue())->toBe(1);
});
