<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Contract;

use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * Used by ingestion once per distinct code in a batch. Returns null for any
 * unknown or invalid code, never throws: the event becomes a rejection.
 */
interface MeterCatalog
{
    public function find(TenantContext $tenant, string $code): ?MeterDescriptor;

    /**
     * Alphabetical.
     *
     * @return list<string>
     */
    public function codes(TenantContext $tenant): array;
}
