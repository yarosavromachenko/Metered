<?php

declare(strict_types=1);

use Filament\Actions\Action;
use Metered\Invoicing\Application\Command\CloseSubscriptionPeriods;
use Metered\Invoicing\Application\Command\CloseSubscriptionPeriodsHandler;
use Metered\Invoicing\Domain\CreditNote\CreditNoteRepository;
use Metered\Invoicing\Domain\Invoice\Invoice;
use Metered\Invoicing\Domain\Invoice\InvoiceRepository;
use Metered\Invoicing\Domain\Invoice\InvoiceStatus;
use Metered\Invoicing\Infrastructure\Eloquent\Invoice as InvoiceRow;
use Metered\Invoicing\Presentation\Filament\Actions\DownloadInvoicePdfAction;
use Metered\Invoicing\Presentation\Filament\Actions\FinalizeInvoiceAction;
use Metered\Invoicing\Presentation\Filament\Actions\PayInvoiceAction;
use Metered\Invoicing\Presentation\Filament\Actions\VoidInvoiceAction;
use Metered\Invoicing\Presentation\Pdf\InvoicePdf;
use Metered\Shared\Domain\Access\Actor;
use Metered\Tenancy\Domain\Project;
use Metered\Tenancy\Domain\Role;

use function Pest\Laravel\get;

use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\InvoicingScenario;
use Tests\Support\PanelSession;
use Tests\Support\TenantFactory;

/**
 * Invoices and the ledger in the panel. Actions are driven through the same
 * `run()` their buttons call; pages are requested for real, to hold the rule
 * every screen answers to — this project's rows and no one else's.
 */

/**
 * The signed-in project's first period, closed: INV-000001 for 41.50 EUR, to
 * the customer $reference.
 */
function panelInvoice(Project $project, string $reference = 'cus_ours'): Invoice
{
    $clock = new MockClock('2026-01-31 14:00:00', 'UTC');
    $scenario = InvoicingScenario::in($project, $clock, $reference);
    $scenario->usage('2026-02-01T10:00:00Z', '1250');
    $clock->modify('2026-02-28 15:00:00');

    [$invoice] = app(CloseSubscriptionPeriodsHandler::class)->handle(
        new CloseSubscriptionPeriods($scenario->tenant, $scenario->subscription->id, Actor::system('test')),
    );

    return $invoice;
}

it('lists this project’s invoices and no one else’s', function (): void {
    $theirs = TenantFactory::tenant('north-wind');
    panelInvoice($theirs, 'cus_theirs');
    $project = PanelSession::signIn('acme', Role::BillingOperator);
    panelInvoice($project);

    get('/admin/invoices')->assertOk()
        ->assertSee('INV-000001')
        ->assertSee('cus_ours')
        ->assertSee('41.50 EUR')
        ->assertDontSee('cus_theirs');

    get('/admin/ledger-entries')->assertOk()
        ->assertSee('cus_ours')
        ->assertSee('Accounts receivable')
        ->assertDontSee('cus_theirs');
});

it('shows how each line was computed, and what the invoice booked', function (): void {
    $project = PanelSession::signIn('acme', Role::BillingOperator);
    $invoice = panelInvoice($project);

    get('/admin/invoices/' . $invoice->id->value)->assertOk()
        ->assertSee('Invoice INV-000001')
        ->assertSee('Usage of api.requests')
        ->assertSee('1250 × 0.01 EUR = 12.50 EUR')
        ->assertSee('29.00 EUR per period, whatever was used')
        ->assertSee('Dr accounts receivable 41.50 EUR')
        ->assertSee('Cr revenue 41.50 EUR');
});

it('does not open another project’s invoice', function (): void {
    $invoice = panelInvoice(TenantFactory::tenant('north-wind'), 'cus_theirs');
    PanelSession::signIn('acme', Role::BillingOperator);

    get('/admin/invoices/' . $invoice->id->value)->assertNotFound();
});

it('collects, then refuses to void a paid invoice', function (): void {
    $project = PanelSession::signIn('acme', Role::BillingOperator);
    $invoice = panelInvoice($project);

    PayInvoiceAction::run($invoice->id->value);
    VoidInvoiceAction::run($invoice->id->value, ['reason' => 'too late']);

    expect(app(InvoiceRepository::class)->find($project->tenant(), $invoice->id)?->status)->toBe(InvoiceStatus::Paid);
});

it('voids with a credit note that the invoice page then shows', function (): void {
    $project = PanelSession::signIn('acme', Role::BillingOperator);
    $invoice = panelInvoice($project);

    VoidInvoiceAction::run($invoice->id->value, ['reason' => 'Billed on the wrong plan']);

    expect(app(CreditNoteRepository::class)->forInvoice($project->tenant(), $invoice->id)?->reason)->toBe('Billed on the wrong plan');

    get('/admin/invoices/' . $invoice->id->value)->assertOk()
        ->assertSee('CN-000001')
        ->assertSee('Billed on the wrong plan')
        ->assertSee('Dr revenue 41.50 EUR');
});

it('reports a refusal instead of failing, when finalizing what is already final', function (): void {
    $project = PanelSession::signIn('acme', Role::BillingOperator);
    $invoice = panelInvoice($project);

    FinalizeInvoiceAction::run($invoice->id->value);

    expect(app(InvoiceRepository::class)->find($project->tenant(), $invoice->id)?->number?->sequence)->toBe(1);
});

it('offers the money buttons only to those who move money', function (Role $role, bool $offered): void {
    $project = PanelSession::signIn('acme', $role);
    $invoice = panelInvoice($project);

    $row = InvoiceRow::query()->findOrFail($invoice->id->value);
    $visible = static fn(Action $action): bool => $action->record($row)->isVisible();

    expect($visible(PayInvoiceAction::make()))->toBe($offered)
        ->and($visible(VoidInvoiceAction::make()))->toBe($offered)
        ->and($visible(FinalizeInvoiceAction::make()))->toBeFalse()
        ->and($visible(DownloadInvoicePdfAction::make()))->toBeTrue();
})->with([
    'billing operator' => [Role::BillingOperator, true],
    'owner' => [Role::Owner, true],
    'admin' => [Role::Admin, false],
    'viewer' => [Role::Viewer, false],
]);

it('downloads the invoice as a PDF', function (): void {
    $project = PanelSession::signIn('acme', Role::BillingOperator);
    $invoice = panelInvoice($project);

    $row = InvoiceRow::query()->findOrFail($invoice->id->value);
    $download = DownloadInvoicePdfAction::make()->record($row)->call();

    expect($download)->toBeInstanceOf(StreamedResponse::class)
        ->and($download instanceof StreamedResponse ? $download->headers->get('content-disposition') : null)->toContain('INV-000001.pdf')
        ->and(InvoicePdf::render($row))->toStartWith('%PDF-');
});
