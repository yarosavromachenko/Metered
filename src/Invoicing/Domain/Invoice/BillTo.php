<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Invoice;

/**
 * A copy of the customer at build time, so renames don't change old invoices.
 */
final readonly class BillTo
{
    public function __construct(
        public string $reference,
        public string $name,
    ) {}
}
