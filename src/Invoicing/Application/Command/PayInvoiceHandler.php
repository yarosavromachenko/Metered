<?php

declare(strict_types=1);

namespace Metered\Invoicing\Application\Command;

use Metered\Invoicing\Application\Payment\PaymentGateway;
use Metered\Invoicing\Application\Payment\PaymentRequest;
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
 * Locks the invoice row during the gateway call, so a concurrent payment waits
 * and then finds it paid. (With a real provider the lock would span a network
 * call; its idempotency on the invoice id would allow releasing it earlier.)
 */
final readonly class PayInvoiceHandler
{
    public function __construct(
        private InvoiceRepository $invoices,
        private PaymentGateway $gateway,
        private Ledger $ledger,
        private OutboxWriter $outbox,
        private Transactions $transactions,
        private Authorizer $authorizer,
        private IdentifierGenerator $ids,
        private ClockInterface $clock,
        private AuditLogger $audit,
    ) {}

    public function handle(PayInvoice $command): Invoice
    {
        $this->authorizer->ensure($command->actor, $command->tenant->organizationId, Permission::MoveMoney);

        return $this->transactions->run(function () use ($command): Invoice {
            $open = $this->invoices->findForUpdate($command->tenant, $command->invoiceId);

            if (! $open instanceof Invoice) {
                throw InvoiceNotFound::of($command->invoiceId);
            }

            // Checked before calling the gateway.
            $now = $this->clock->now();
            $paid = $open->pay($now);

            $result = $this->gateway->charge(new PaymentRequest(
                $open->id,
                $open->customerId,
                $open->total(),
                sprintf('Invoice %s', $open->number),
            ));

            if (! $result->succeeded) {
                throw PaymentDeclined::because($result->declineReason ?? 'no reason given');
            }

            $this->invoices->save($paid);
            $this->ledger->record(LedgerTransaction::paymentReceived($this->ids->generate(), $paid));
            $this->outbox->append(InvoiceMessages::about($this->ids->generate(), $paid, 'invoice.paid', $now, ['payment_reference' => $result->reference]));

            $this->audit->record(new AuditEntry(
                organizationId: $command->tenant->organizationId,
                actor: $command->actor->label,
                action: 'invoice.paid',
                subjectType: 'invoice',
                subjectId: $paid->id->value,
                payload: ['number' => (string) $paid->number, 'payment_reference' => $result->reference],
                occurredAt: $now,
            ));

            return $paid;
        });
    }
}
