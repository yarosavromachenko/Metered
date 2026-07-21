<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Plan;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

interface PlanVersionRepository
{
    /**
     * Stores the version with exactly the prices it holds. A published version
     * cannot be saved again — the database refuses, as the type does.
     */
    public function save(PlanVersion $version): void;

    public function find(TenantContext $tenant, Uuid $id): ?PlanVersion;

    /**
     * @return list<PlanVersion> newest first
     */
    public function listForPlan(TenantContext $tenant, Uuid $planId): array;

    /**
     * The number the plan's next version would take. A guess under
     * concurrency; the unique (plan, number) constraint settles it.
     */
    public function nextNumber(TenantContext $tenant, Uuid $planId): int;
}
