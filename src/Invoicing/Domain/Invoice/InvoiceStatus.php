<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Invoice;

/**
 * See docs/domain.md, state machines. Finalized never returns to draft.
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Finalized = 'finalized';
    case Paid = 'paid';
    case Void = 'void';
}
