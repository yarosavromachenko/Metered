<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Metered\Billing\Domain\Plan\Price;
use Metered\Billing\Domain\Pricing\PerUnit;
use Metered\Billing\Domain\Subscription\SubscriptionRepository;
use Metered\Billing\Domain\Subscription\SubscriptionStatus;
use Metered\Invoicing\Application\Command\CloseSubscriptionPeriods;
use Metered\Invoicing\Application\Command\CloseSubscriptionPeriodsHandler;
use Metered\Invoicing\Application\Command\InvoiceNotFound;
use Metered\Invoicing\Domain\Invoice\Invoice;
use Metered\Invoicing\Domain\Invoice\InvoiceLine;
use Metered\Invoicing\Domain\Invoice\InvoiceStatus;
use Metered\Invoicing\Domain\Ledger\Account;
use Metered\Invoicing\Domain\Ledger\Ledger;
use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Money\UnitPrice;
use Tests\Support\CatalogFactory;
use Tests\Support\InvoicingScenario;
use Tests\Support\TenantFactory;

/**
 * @return list<Invoice>
 */
function closePeriods(InvoicingScenario $scenario): array
{
    return app(CloseSubscriptionPeriodsHandler::class)->handle(
        new CloseSubscriptionPeriods($scenario->tenant, $scenario->subscription->id, Actor::system('test:close-periods')),
    );
}

/**
 * @return list<string> each line as "kind description quantity amount"
 */
function linesOf(Invoice $invoice): array
{
    return array_map(
        static fn(InvoiceLine $line): string => trim(sprintf('%s | %s | %s | %s', $line->kind->value, $line->description, $line->quantity ?? '', $line->amount)),
        $invoice->lines,
    );
}

it('closes nothing until the period’s grace window has passed', function (): void {
    $scenario = InvoicingScenario::start()->at('2026-02-28 14:59:59.999999');

    expect(closePeriods($scenario))->toBe([])
        ->and(DB::table('invoices')->count())->toBe(0);
});

it('invoices a period once its grace window has passed: numbered, booked and announced', function (): void {
    $scenario = InvoicingScenario::start();
    $scenario->usage('2026-02-01T10:00:00Z', '1000');
    $scenario->usage('2026-02-20T10:00:00Z', '250');

    [$invoice] = closePeriods($scenario->at('2026-02-28 15:00:00'));

    expect($invoice->status)->toBe(InvoiceStatus::Finalized)
        ->and((string) $invoice->number)->toBe('INV-000001')
        ->and((string) $invoice->period)->toBe('2026-01-31 – 2026-02-28')
        ->and(linesOf($invoice))->toBe([
            'fixed | Flat fee |  | 29.00 EUR',
            'usage | Usage of api.requests | 1250.000000 | 12.50 EUR',
        ])
        ->and($invoice->lines[1]->calculation)->toBe(['1250 × 0.01 EUR = 12.50 EUR'])
        ->and((string) app(Ledger::class)->balance($scenario->tenant, $scenario->customer->id, Account::AccountsReceivable, 'EUR'))->toBe('41.50 EUR')
        ->and((string) app(Ledger::class)->balance($scenario->tenant, $scenario->customer->id, Account::Revenue, 'EUR'))->toBe('41.50 EUR')
        ->and(DB::table('outbox_messages')->where('aggregate_id', $invoice->id->value)->pluck('type')->all())->toBe(['invoice.finalized'])
        ->and(DB::table('audit_log')->where('subject_id', $invoice->id->value)->pluck('action')->all())->toBe(['invoice.finalized']);
});

it('bills usage arriving inside the grace window on the period’s own invoice', function (): void {
    $scenario = InvoicingScenario::start();
    $scenario->at('2026-02-28 14:30:00')->usage('2026-02-28T13:00:00Z', '300');

    [$invoice] = closePeriods($scenario->at('2026-02-28 15:00:00'));

    expect(linesOf($invoice)[1])->toBe('usage | Usage of api.requests | 300.000000 | 3.00 EUR');
});

it('closes one period once, however often it is asked', function (): void {
    $scenario = InvoicingScenario::start()->at('2026-03-01 00:00:00');

    $first = closePeriods($scenario);
    $second = closePeriods($scenario);

    expect($first)->toHaveCount(1)
        ->and($second)->toBe([])
        ->and(DB::table('invoices')->count())->toBe(1)
        ->and(DB::table('document_sequences')->value('last_number'))->toBe(1);
});

it('catches up on every period missed while nothing ran, in order', function (): void {
    $scenario = InvoicingScenario::start()->at('2026-05-15 00:00:00');

    $invoices = closePeriods($scenario);

    expect(array_map(static fn(Invoice $i): string => $i->number . ' ' . $i->period, $invoices))->toBe([
        'INV-000001 2026-01-31 – 2026-02-28',
        'INV-000002 2026-02-28 – 2026-03-31',
        'INV-000003 2026-03-31 – 2026-04-30',
    ]);
});

it('bills usage that reached a finalized period on the next invoice, as a late line', function (): void {
    $scenario = InvoicingScenario::start();
    $scenario->usage('2026-02-10T10:00:00Z', '1000');
    [$first] = closePeriods($scenario->at('2026-02-28 15:00:00'));

    // Six days later an event from 27 February arrives: its period is final.
    $scenario->at('2026-03-06 09:00:00')->usage('2026-02-27T08:00:00Z', '200');
    $scenario->usage('2026-03-10T10:00:00Z', '50');

    [$second] = closePeriods($scenario->at('2026-03-31 15:00:00'));

    expect(linesOf($first)[1])->toBe('usage | Usage of api.requests | 1000.000000 | 10.00 EUR')
        ->and(linesOf($second))->toBe([
            'fixed | Flat fee |  | 29.00 EUR',
            'usage | Usage of api.requests | 50.000000 | 0.50 EUR',
            'late | Late usage of api.requests for 2026-01-31 – 2026-02-28 | 200.000000 | 2.00 EUR',
        ])
        ->and((string) $second->lines[2]->covers)->toBe('2026-01-31 – 2026-02-28')
        ->and($second->lines[2]->calculation)->toBe([
            '2026-01-31 – 2026-02-28 now holds 1200.000000: 12.00 EUR',
            '1200 × 0.01 EUR = 12.00 EUR',
            'already billed for 1000.000000: 10.00 EUR',
        ])
        ->and((string) $second->total())->toBe('31.50 EUR');
});

it('bills late usage once: the next invoice after that has nothing late on it', function (): void {
    $scenario = InvoicingScenario::start();
    closePeriods($scenario->at('2026-02-28 15:00:00'));
    $scenario->at('2026-03-02 00:00:00')->usage('2026-02-27T08:00:00Z', '200');
    closePeriods($scenario->at('2026-03-31 15:00:00'));

    [$third] = closePeriods($scenario->at('2026-04-30 15:00:00'));

    expect(array_column(array_map(static fn(InvoiceLine $l): array => ['kind' => $l->kind->value], $third->lines), 'kind'))->toBe(['fixed', 'usage']);
});

it('settles an invoice for nothing at once, without booking anything', function (): void {
    $scenario = InvoicingScenario::start();
    $version = CatalogFactory::version(CatalogFactory::plan($scenario->tenant, 'usage-only'), [
        Price::metered(app(IdentifierGenerator::class)->generate(), PerUnit::at(UnitPrice::fromString('0.01', 'EUR')), $scenario->meter->id),
    ]);
    $subscription = CatalogFactory::subscription($scenario->customer, $version, $scenario->clock->now());
    $scenario->at('2026-03-01 00:00:00');

    [$invoice] = app(CloseSubscriptionPeriodsHandler::class)->handle(new CloseSubscriptionPeriods($scenario->tenant, $subscription->id, Actor::system('test')));

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and((string) $invoice->total())->toBe('0.00 EUR')
        ->and(DB::table('ledger_transactions')->count())->toBe(0);
});

it('bills a subscription canceled at period end one last time, then lets it lapse', function (): void {
    $scenario = InvoicingScenario::start();
    $repository = app(SubscriptionRepository::class);
    $repository->save($scenario->subscription->cancelAtPeriodEnd(new DateTimeImmutable('2026-02-10T00:00:00Z')));

    $invoices = closePeriods($scenario->at('2026-06-01 00:00:00'));

    expect($invoices)->toHaveCount(1)
        ->and($repository->find($scenario->tenant, $scenario->subscription->id)?->status)->toBe(SubscriptionStatus::Canceled)
        ->and(closePeriods($scenario->at('2026-07-01 00:00:00')))->toBe([]);
});

it('bills a subscription canceled mid-period up to the cancellation', function (): void {
    $scenario = InvoicingScenario::start();
    app(SubscriptionRepository::class)->save($scenario->subscription->cancelNow(new DateTimeImmutable('2026-02-10T12:00:00Z')));
    $scenario->usage('2026-02-10T11:00:00Z', '10');
    $scenario->usage('2026-02-10T12:00:00Z', '99');

    [$invoice] = closePeriods($scenario->at('2026-02-10 13:00:00'));

    expect((string) $invoice->period)->toBe('2026-01-31 – 2026-02-10')
        ->and(linesOf($invoice)[1])->toBe('usage | Usage of api.requests | 10.000000 | 0.10 EUR');
});

it('does not close another tenant’s subscription', function (): void {
    $scenario = InvoicingScenario::start();
    $rival = TenantFactory::tenant('north-wind')->tenant();

    app(CloseSubscriptionPeriodsHandler::class)->handle(new CloseSubscriptionPeriods($rival, $scenario->subscription->id, Actor::system('test')));
})->throws(InvoiceNotFound::class, 'No subscription');
