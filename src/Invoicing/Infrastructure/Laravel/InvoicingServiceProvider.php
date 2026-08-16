<?php

declare(strict_types=1);

namespace Metered\Invoicing\Infrastructure\Laravel;

use Illuminate\Contracts\Foundation\Application;
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
use Metered\Invoicing\Infrastructure\Persistence\DatabaseLedger;
use Metered\Invoicing\Presentation\Console\ClosePeriodsCommand;
use Metered\Shared\Application\Transaction\Transactions;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Usage\Application\Contract\UsageTotals;
use Psr\Clock\ClockInterface;

/**
 * Wires invoicing: its repositories, the ledger, the gapless counters, the
 * fake payment provider, and the two windows the period close runs on.
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
                // Late usage can reach back as far as an event may be old when
                // it is accepted, plus the grace in which it becomes an aggregate.
                self::configInt($app, 'metered.usage.acceptance.max_age_seconds', 604_800)
                    + self::configInt($app, 'metered.invoicing.grace_seconds', 3600),
            ),
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ClosePeriodsCommand::class]);
        }
    }

    private static function configInt(Application $app, string $key, int $default): int
    {
        $value = $app->make('config')->get($key);

        return is_int($value) ? $value : $default;
    }
}
