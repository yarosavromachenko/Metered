<?php

declare(strict_types=1);

namespace Metered\Invoicing\Application\Command;

use Metered\Invoicing\Application\Metrics\InvoicingMetrics;
use Metered\Invoicing\Domain\Invoice\DocumentNumbering;
use Metered\Invoicing\Domain\Invoice\Invoice;
use Metered\Invoicing\Domain\Invoice\InvoiceRepository;
use Metered\Invoicing\Domain\Ledger\Ledger;
use Metered\Invoicing\Domain\Ledger\LedgerTransaction;
use Metered\Shared\Application\Audit\AuditLogger;
use Metered\Shared\Application\Metrics\Metrics;
use Metered\Shared\Application\Outbox\OutboxWriter;
use Metered\Shared\Application\Transaction\Transactions;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Audit\AuditEntry;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Tenancy\Application\Contract\Authorizer;
use Psr\Clock\ClockInterface;

/**
 * Number, ledger entries and outbox message in one transaction.
 */
final readonly class FinalizeInvoiceHandler
{
    public function __construct(
        private InvoiceRepository $invoices,
        private DocumentNumbering $numbering,
        private Ledger $ledger,
        private OutboxWriter $outbox,
        private Transactions $transactions,
        private Authorizer $authorizer,
        private IdentifierGenerator $ids,
        private ClockInterface $clock,
        private AuditLogger $audit,
        private Metrics $metrics,
    ) {}

    public function handle(FinalizeInvoice $command): Invoice
    {
        $this->authorizer->ensure($command->actor, $command->tenant->organizationId, Permission::MoveMoney);

        $invoice = $this->transactions->run(function () use ($command): Invoice {
            // Lock order: invoice row, then the counter, to avoid deadlocks.
            $draft = $this->invoices->findForUpdate($command->tenant, $command->invoiceId);

            if (! $draft instanceof Invoice) {
                throw InvoiceNotFound::of($command->invoiceId);
            }

            $now = $this->clock->now();
            $invoice = $draft->finalize($this->numbering->nextInvoiceNumber($command->tenant->organizationId), $now);
            $this->invoices->save($invoice);

            if (! $invoice->total()->isZero()) {
                $this->ledger->record(LedgerTransaction::invoiceFinalized($this->ids->generate(), $invoice));
            }

            $this->outbox->append(InvoiceMessages::about($this->ids->generate(), $invoice, 'invoice.finalized', $now));

            $this->audit->record(new AuditEntry(
                organizationId: $command->tenant->organizationId,
                actor: $command->actor->label,
                action: 'invoice.finalized',
                subjectType: 'invoice',
                subjectId: $invoice->id->value,
                payload: ['number' => (string) $invoice->number, 'total_minor' => $invoice->total()->minorUnits(), 'currency' => $invoice->currency],
                occurredAt: $now,
            ));

            return $invoice;
        });

        // After commit.
        $this->metrics->add(InvoicingMetrics::invoicesFinalized());

        return $invoice;
    }
}
