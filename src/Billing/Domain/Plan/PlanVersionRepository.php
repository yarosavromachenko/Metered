<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Plan;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

interface PlanVersionRepository
{
    /**
     * Saving a published version fails in the database as well.
     */
    public function save(PlanVersion $version): void;

    public function find(TenantContext $tenant, Uuid $id): ?PlanVersion;

    /**
     * @return list<PlanVersion> newest first
     */
    public function listForPlan(TenantContext $tenant, Uuid $planId): array;

    /**
     * Not race-free; the unique (plan, number) constraint decides.
     */
    public function nextNumber(TenantContext $tenant, Uuid $planId): int;
}
