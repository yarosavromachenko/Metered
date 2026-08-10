<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Invoice;

/**
 * Where an invoice is in its life (docs/domain.md, state machines).
 *
 * A finalized invoice never goes back to draft: its number has been used and
 * its amount booked, and both are history the ledger relies on.
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Finalized = 'finalized';
    case Paid = 'paid';
    case Void = 'void';
}
