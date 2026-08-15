<?php

declare(strict_types=1);

namespace Metered\Invoicing\Infrastructure\Laravel;

use Illuminate\Support\ServiceProvider;
use Metered\Invoicing\Domain\CreditNote\CreditNoteRepository;
use Metered\Invoicing\Domain\Invoice\BillingHistory;
use Metered\Invoicing\Domain\Invoice\DocumentNumbering;
use Metered\Invoicing\Domain\Invoice\InvoiceRepository;
use Metered\Invoicing\Domain\Ledger\Ledger;
use Metered\Invoicing\Infrastructure\Persistence\DatabaseBillingHistory;
use Metered\Invoicing\Infrastructure\Persistence\DatabaseCreditNoteRepository;
use Metered\Invoicing\Infrastructure\Persistence\DatabaseDocumentNumbering;
use Metered\Invoicing\Infrastructure\Persistence\DatabaseInvoiceRepository;
use Metered\Invoicing\Infrastructure\Persistence\DatabaseLedger;

/**
 * Wires invoicing: its repositories, the ledger and the gapless counters.
 */
final class InvoicingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InvoiceRepository::class, DatabaseInvoiceRepository::class);
        $this->app->singleton(BillingHistory::class, DatabaseBillingHistory::class);
        $this->app->singleton(DocumentNumbering::class, DatabaseDocumentNumbering::class);
        $this->app->singleton(CreditNoteRepository::class, DatabaseCreditNoteRepository::class);
        $this->app->singleton(Ledger::class, DatabaseLedger::class);
    }
}
