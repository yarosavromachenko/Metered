<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Subscription;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

interface SubscriptionRepository
{
    /**
     * Stores the subscription with exactly the phases it holds.
     */
    public function save(Subscription $subscription): void;

    public function find(TenantContext $tenant, Uuid $id): ?Subscription;

    /**
     * @return list<Subscription> newest first
     */
    public function listForCustomer(TenantContext $tenant, Uuid $customerId): array;
}
