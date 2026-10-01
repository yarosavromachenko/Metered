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
 * Collects a finalized invoice through the payment gateway and books the cash.
 *
 * The invoice row is held while the gateway answers, so a second operator
 * paying the same invoice waits and then finds it paid. With a real provider
 * that lock would be held across a network call; the provider's idempotency
 * on the invoice id is what would make releasing it early safe.
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

            // Asked of the invoice before the gateway is: a draft or a paid
            // invoice is refused without anyone being charged.
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
