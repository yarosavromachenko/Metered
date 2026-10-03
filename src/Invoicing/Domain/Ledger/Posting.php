<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Ledger;

enum Posting: string
{
    /** Dr Accounts Receivable / Cr Revenue */
    case InvoiceFinalized = 'invoice.finalized';

    /** Dr Cash / Cr Accounts Receivable */
    case PaymentReceived = 'payment.received';

    /** Dr Revenue / Cr Accounts Receivable */
    case CreditNoteIssued = 'credit_note.issued';
}
