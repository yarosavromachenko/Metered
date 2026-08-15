<?php

declare(strict_types=1);

namespace Metered\Invoicing\Application\Command;

use Metered\Invoicing\Domain\Invoice\DocumentNumbering;
use Metered\Invoicing\Domain\Invoice\Invoice;
use Metered\Invoicing\Domain\Invoice\InvoiceRepository;
use Metered\Invoicing\Domain\Ledger\Ledger;
use Metered\Invoicing\Domain\Ledger\LedgerTransaction;
use Metered\Shared\Application\Audit\AuditLogger;
use Metered\Shared\Application\Outbox\OutboxWriter;
use Metered\Shared\Application\Transaction\Transactions;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Audit\AuditEntry;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Tenancy\Application\Contract\Authorizer;
use Psr\Clock\ClockInterface;

/**
 * Numbers a draft, books it, and announces it — in one transaction, so that a
 * number is never used without an invoice to show for it, and an invoice is
 * never final without its entries.
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
    ) {}

    public function handle(FinalizeInvoice $command): Invoice
    {
        $this->authorizer->ensure($command->actor, $command->tenant->organizationId, Permission::MoveMoney);

        return $this->transactions->run(function () use ($command): Invoice {
            // The invoice row first, then the organization's counter: every
            // finalization takes the two locks in that order, so they queue
            // rather than deadlock.
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
                actor: $command->actor->label,
                action: 'invoice.finalized',
                subjectType: 'invoice',
                subjectId: $invoice->id->value,
                payload: ['number' => (string) $invoice->number, 'total_minor' => $invoice->total()->minorUnits(), 'currency' => $invoice->currency],
                occurredAt: $now,
            ));

            return $invoice;
        });
    }
}
