<?php

declare(strict_types=1);

namespace Metered\Tenancy\Domain;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

interface ApiKeyRepository
{
    public function save(ApiKey $key): void;

    /**
     * Unscoped: authentication is where the tenant context comes from. The
     * prefix is unique platform-wide.
     */
    public function findByPrefix(string $prefix): ?ApiKey;

    public function find(TenantContext $tenant, Uuid $id): ?ApiKey;

    /**
     * @return list<ApiKey>
     */
    public function listFor(TenantContext $tenant): array;
}
