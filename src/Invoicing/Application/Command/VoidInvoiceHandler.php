<?php

declare(strict_types=1);

namespace Metered\Invoicing\Application\Command;

use Metered\Invoicing\Domain\CreditNote\CreditNote;
use Metered\Invoicing\Domain\CreditNote\CreditNoteRepository;
use Metered\Invoicing\Domain\Invoice\DocumentNumbering;
use Metered\Invoicing\Domain\Invoice\Invoice;
use Metered\Invoicing\Domain\Invoice\InvoiceRepository;
use Metered\Invoicing\Domain\Invoice\InvoiceStatus;
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
 * A draft is discarded. A finalized invoice is voided with a credit note that
 * reverses its entries; both documents remain.
 */
final readonly class VoidInvoiceHandler
{
    public function __construct(
        private InvoiceRepository $invoices,
        private CreditNoteRepository $creditNotes,
        private DocumentNumbering $numbering,
        private Ledger $ledger,
        private OutboxWriter $outbox,
        private Transactions $transactions,
        private Authorizer $authorizer,
        private IdentifierGenerator $ids,
        private ClockInterface $clock,
        private AuditLogger $audit,
    ) {}

    public function handle(VoidInvoice $command): Invoice
    {
        $this->authorizer->ensure($command->actor, $command->tenant->organizationId, Permission::MoveMoney);

        return $this->transactions->run(function () use ($command): Invoice {
            $invoice = $this->invoices->findForUpdate($command->tenant, $command->invoiceId);

            if (! $invoice instanceof Invoice) {
                throw InvoiceNotFound::of($command->invoiceId);
            }

            $now = $this->clock->now();

            if ($invoice->status === InvoiceStatus::Draft) {
                $discarded = $invoice->discard($now);
                $this->invoices->save($discarded);
                $this->record($command, $discarded, 'invoice.discarded', []);

                return $discarded;
            }

            $voided = $invoice->void($now);
            $note = CreditNote::voiding($this->ids->generate(), $this->numbering->nextCreditNoteNumber($command->tenant->organizationId), $voided, $command->reason);

            $this->invoices->save($voided);
            $this->creditNotes->add($note);
            $this->ledger->record(LedgerTransaction::creditNoteIssued($this->ids->generate(), $voided, $note));
            $this->outbox->append(InvoiceMessages::about($this->ids->generate(), $voided, 'invoice.voided', $now, [
                'credit_note_number' => (string) $note->number,
                'reason' => $note->reason,
            ]));
            $this->record($command, $voided, 'invoice.voided', ['credit_note' => (string) $note->number, 'reason' => $note->reason]);

            return $voided;
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function record(VoidInvoice $command, Invoice $invoice, string $action, array $payload): void
    {
        $this->audit->record(new AuditEntry(
            organizationId: $command->tenant->organizationId,
            actor: $command->actor->label,
            action: $action,
            subjectType: 'invoice',
            subjectId: $invoice->id->value,
            payload: $payload,
            occurredAt: $this->clock->now(),
        ));
    }
}
