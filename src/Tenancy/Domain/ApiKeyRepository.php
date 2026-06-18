<?php

declare(strict_types=1);

namespace Metered\Tenancy\Domain;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

interface ApiKeyRepository
{
    public function save(ApiKey $key): void;

    /**
     * The one deliberately unscoped lookup in the system.
     *
     * Authentication is where a tenant context comes from, so it cannot
     * require one. The prefix is unique platform-wide, and the row that comes
     * back carries the tenant — every query after this point is scoped by it.
     */
    public function findByPrefix(string $prefix): ?ApiKey;

    public function find(TenantContext $tenant, Uuid $id): ?ApiKey;

    /**
     * @return list<ApiKey>
     */
    public function listFor(TenantContext $tenant): array;
}
