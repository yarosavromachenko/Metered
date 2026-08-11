<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\CreditNote;

use DateTimeImmutable;
use Metered\Invoicing\Domain\Exception\InvoiceTransitionRefused;
use Metered\Invoicing\Domain\Invoice\DocumentNumber;
use Metered\Invoicing\Domain\Invoice\Invoice;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * The document that reverses a finalized invoice.
 *
 * Numbered on its own gapless sequence and booked `Dr Revenue / Cr Accounts
 * Receivable`. The invoice it reverses is not changed beyond being marked
 * void: both documents stay, and together they explain the balance.
 */
final readonly class CreditNote
{
    private function __construct(
        public Uuid $id,
        public TenantContext $tenant,
        public Uuid $invoiceId,
        public Uuid $customerId,
        public DocumentNumber $number,
        public Money $amount,
        public string $reason,
        public DateTimeImmutable $issuedAt,
    ) {}

    /**
     * The whole of a voided invoice, reversed.
     */
    public static function voiding(Uuid $id, DocumentNumber $number, Invoice $invoice, string $reason): self
    {
        // Only `void()` sets both: a discarded draft is void without a number,
        // and there is nothing booked to reverse.
        if (! $invoice->number instanceof DocumentNumber || ! $invoice->voidedAt instanceof DateTimeImmutable) {
            throw InvoiceTransitionRefused::from($invoice->status->value, 'credited');
        }

        return new self($id, $invoice->tenant, $invoice->id, $invoice->customerId, $number, $invoice->total(), trim($reason), $invoice->voidedAt);
    }

    /**
     * @internal for the repository, rebuilding a note exactly as it was stored
     */
    public static function restore(
        Uuid $id,
        TenantContext $tenant,
        Uuid $invoiceId,
        Uuid $customerId,
        DocumentNumber $number,
        Money $amount,
        string $reason,
        DateTimeImmutable $issuedAt,
    ): self {
        return new self($id, $tenant, $invoiceId, $customerId, $number, $amount, $reason, $issuedAt);
    }
}
