<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Contract;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * Unscoped project lookups for operator work (`usage:reconcile`, scheduled
 * sweeps). The request path uses the organization-scoped repository.
 */
interface ProjectDirectory
{
    /**
     * @return list<TenantContext>
     */
    public function all(): array;

    public function find(Uuid $projectId): ?TenantContext;

    /**
     * ISO 4217 code; null when the project does not exist.
     */
    public function currencyOf(TenantContext $tenant): ?string;
}
