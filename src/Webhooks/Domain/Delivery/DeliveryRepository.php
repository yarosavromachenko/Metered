<?php

declare(strict_types=1);

namespace Metered\Webhooks\Domain\Delivery;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

interface DeliveryRepository
{
    /**
     * False when the endpoint already has a delivery for this event.
     */
    public function add(Delivery $delivery): bool;

    public function save(Delivery $delivery): void;

    public function find(TenantContext $tenant, Uuid $id): ?Delivery;

    public function findForUpdate(TenantContext $tenant, Uuid $id): ?Delivery;

    /**
     * All tenants, oldest first.
     *
     * @return list<Delivery>
     */
    public function dueAt(DateTimeImmutable $now, int $limit): array;
}
