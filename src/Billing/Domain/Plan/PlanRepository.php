<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Plan;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

interface PlanRepository
{
    public function save(Plan $plan): void;

    public function find(TenantContext $tenant, Uuid $id): ?Plan;

    public function findByCode(TenantContext $tenant, PlanCode $code): ?Plan;

    /**
     * @return list<Plan>
     */
    public function listFor(TenantContext $tenant): array;
}
