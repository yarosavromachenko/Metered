<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Contract;

use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * Returns null for an unknown reference, never throws.
 */
interface CustomerDirectory
{
    public function find(TenantContext $tenant, string $reference): ?CustomerDescriptor;
}
