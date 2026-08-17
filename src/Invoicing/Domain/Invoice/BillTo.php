<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Invoice;

/**
 * Who the invoice is addressed to, as they were when it was built.
 *
 * A copy rather than a reference: a customer renamed next year must not
 * change what last year's invoice says.
 */
final readonly class BillTo
{
    public function __construct(
        public string $reference,
        public string $name,
    ) {}
}
