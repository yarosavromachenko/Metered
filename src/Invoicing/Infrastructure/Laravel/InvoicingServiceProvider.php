<?php

declare(strict_types=1);

namespace Metered\Invoicing\Infrastructure\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Metered\Billing\Application\Contract\SubscriptionBilling;
use Metered\Invoicing\Application\Command\CloseSubscriptionPeriodsHandler;
use Metered\Invoicing\Application\Command\FinalizeInvoiceHandler;
use Metered\Invoicing\Application\Payment\PaymentGateway;
use Metered\Invoicing\Domain\CreditNote\CreditNoteRepository;
use Metered\Invoicing\Domain\Invoice\BillingHistory;
use Metered\Invoicing\Domain\Invoice\DocumentNumbering;
use Metered\Invoicing\Domain\Invoice\InvoiceRepository;
use Metered\Invoicing\Domain\Ledger\Ledger;
use Metered\Invoicing\Infrastructure\Payment\FakePaymentGateway;
use Metered\Invoicing\Infrastructure\Persistence\DatabaseBillingHistory;
use Metered\Invoicing\Infrastructure\Persistence\DatabaseCreditNoteRepository;
use Metered\Invoicing\Infrastructure\Persistence\DatabaseDocumentNumbering;
use Metered\Invoicing\Infrastructure\Persistence\DatabaseInvoiceRepository;
use Metered\Invoicing\Infrastructure\Persistence\DatabaseInvoicingPurger;
use Metered\Invoicing\Infrastructure\Persistence\DatabaseLedger;
use Metered\Invoicing\Presentation\Console\ClosePeriodsCommand;
use Metered\Invoicing\Presentation\Http\InvoicePdfController;
use Metered\Invoicing\Presentation\Http\ListInvoicesController;
use Metered\Invoicing\Presentation\Http\PayInvoiceController;
use Metered\Invoicing\Presentation\Http\ShowInvoiceController;
use Metered\Invoicing\Presentation\Http\VoidInvoiceController;
use Metered\Shared\Application\Transaction\Transactions;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Tenancy\Application\Contract\TenantDataPurger;
use Metered\Usage\Application\Contract\UsageTotals;
use Psr\Clock\ClockInterface;

final class InvoicingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InvoiceRepository::class, DatabaseInvoiceRepository::class);
        $this->app->singleton(BillingHistory::class, DatabaseBillingHistory::class);
        $this->app->singleton(DocumentNumbering::class, DatabaseDocumentNumbering::class);
        $this->app->singleton(CreditNoteRepository::class, DatabaseCreditNoteRepository::class);
        $this->app->singleton(Ledger::class, DatabaseLedger::class);
        $this->app->singleton(PaymentGateway::class, FakePaymentGateway::class);

        $this->app->bind(
            CloseSubscriptionPeriodsHandler::class,
            static fn(Application $app): CloseSubscriptionPeriodsHandler => new CloseSubscriptionPeriodsHandler(
                $app->make(SubscriptionBilling::class),
                $app->make(UsageTotals::class),
                $app->make(InvoiceRepository::class),
                $app->make(BillingHistory::class),
                $app->make(FinalizeInvoiceHandler::class),
                $app->make(Transactions::class),
                $app->make(IdentifierGenerator::class),
                $app->make(ClockInterface::class),
                self::configInt($app, 'metered.invoicing.grace_seconds', 3600),
                // Acceptance window plus grace.
                self::configInt($app, 'metered.usage.acceptance.max_age_seconds', 604_800)
                    + self::configInt($app, 'metered.invoicing.grace_seconds', 3600),
            ),
        );

        $this->app->tag([DatabaseInvoicingPurger::class], TenantDataPurger::TAG);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(base_path('src/Invoicing/Presentation/views'), 'invoicing');

        if ($this->app->runningInConsole()) {
            $this->commands([ClosePeriodsCommand::class]);
        }

        // Admin scope; paying and voiding require an Idempotency-Key.
        Route::middleware(['api', 'api-key:admin', 'throttle-api-key'])
            ->prefix('api/v1')
            ->group(static function (): void {
                Route::get('invoices', ListInvoicesController::class)->name('invoicing.invoices.list');
                Route::get('invoices/{invoice}', ShowInvoiceController::class)->name('invoicing.invoices.show');
                Route::get('invoices/{invoice}/pdf', InvoicePdfController::class)->name('invoicing.invoices.pdf');
                Route::post('invoices/{invoice}/pay', PayInvoiceController::class)->middleware('idempotent')->name('invoicing.invoices.pay');
                Route::post('invoices/{invoice}/void', VoidInvoiceController::class)->middleware('idempotent')->name('invoicing.invoices.void');
            });
    }

    private static function configInt(Application $app, string $key, int $default): int
    {
        $value = $app->make('config')->get($key);

        return is_int($value) ? $value : $default;
    }
}
