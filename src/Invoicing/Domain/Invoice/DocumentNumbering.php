<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Invoice;

use Metered\Shared\Domain\Identifier\Uuid;

/**
 * Hands out the next number of each kind of document in an organization.
 *
 * Gapless, which constrains the implementation: the number is taken inside
 * the transaction that prints it, and a rollback returns it (ADR-0010).
 * Calling this outside a transaction is a bug.
 */
interface DocumentNumbering
{
    public function nextInvoiceNumber(Uuid $organizationId): DocumentNumber;

    public function nextCreditNoteNumber(Uuid $organizationId): DocumentNumber;
}
