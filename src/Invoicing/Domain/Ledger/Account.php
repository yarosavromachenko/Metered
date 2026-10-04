<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Ledger;

/**
 * Per customer and project (ADR-0008).
 */
enum Account: string
{
    /** What the customer owes. */
    case AccountsReceivable = 'accounts_receivable';

    /** What the business has earned from them. */
    case Revenue = 'revenue';

    /** What they have paid. */
    case Cash = 'cash';

    /**
     * True for asset accounts, false for revenue.
     */
    public function growsWithDebits(): bool
    {
        return $this !== self::Revenue;
    }
}
