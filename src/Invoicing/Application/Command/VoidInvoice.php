<?php

declare(strict_types=1);

namespace Metered\Invoicing\Application\Command;

use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * Discards a draft, or voids a finalized invoice with a credit note carrying
 * $reason.
 */
final readonly class VoidInvoice
{
    public function __construct(
        public TenantContext $tenant,
        public Uuid $invoiceId,
        public string $reason,
        public Actor $actor,
    ) {}
}
