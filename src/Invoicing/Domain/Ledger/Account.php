<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Ledger;

/**
 * The accounts a customer's money moves between (ADR-0008).
 *
 * Kept per customer and project: an account is the pair of this name and the
 * customer, so a customer's receivable is the balance of their entries on it.
 */
enum Account: string
{
    /** What the customer owes. */
    case AccountsReceivable = 'accounts_receivable';

    /** What the business has earned from them. */
    case Revenue = 'revenue';

    /** What they have paid. */
    case Cash = 'cash';
}
