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
 * Reverses a finalized invoice. Own gapless numbering; booked `Dr Revenue /
 * Cr Accounts Receivable`. The invoice is only marked void.
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

    public static function voiding(Uuid $id, DocumentNumber $number, Invoice $invoice, string $reason): self
    {
        // A discarded draft is void but has no number.
        if (! $invoice->number instanceof DocumentNumber || ! $invoice->voidedAt instanceof DateTimeImmutable) {
            throw InvoiceTransitionRefused::from($invoice->status->value, 'credited');
        }

        return new self($id, $invoice->tenant, $invoice->id, $invoice->customerId, $number, $invoice->total(), trim($reason), $invoice->voidedAt);
    }

    /**
     * @internal for the repository
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
