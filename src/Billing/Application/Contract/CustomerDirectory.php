<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Contract;

use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * Resolves the reference an event carries to the customer it names.
 *
 * Total, for the same reason as {@see MeterCatalog}: an unknown reference is
 * an answer, not a failure.
 */
interface CustomerDirectory
{
    public function find(TenantContext $tenant, string $reference): ?CustomerDescriptor;
}
