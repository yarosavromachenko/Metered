<?php

declare(strict_types=1);

namespace Metered\Invoicing\Application\Command;

use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

final readonly class FinalizeInvoice
{
    public function __construct(
        public TenantContext $tenant,
        public Uuid $invoiceId,
        public Actor $actor,
    ) {}
}
