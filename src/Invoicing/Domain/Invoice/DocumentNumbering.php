<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Invoice;

use Metered\Shared\Domain\Identifier\Uuid;

/**
 * Must be called inside the transaction that uses the number, so a rollback
 * returns it (ADR-0010).
 */
interface DocumentNumbering
{
    public function nextInvoiceNumber(Uuid $organizationId): DocumentNumber;

    public function nextCreditNoteNumber(Uuid $organizationId): DocumentNumber;
}
