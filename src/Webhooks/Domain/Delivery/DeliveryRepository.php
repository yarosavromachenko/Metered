<?php

declare(strict_types=1);

namespace Metered\Webhooks\Domain\Delivery;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

interface DeliveryRepository
{
    /**
     * Stores a new delivery — unless its endpoint already has one for this
     * event, in which case nothing is written and the answer is false.
     */
    public function add(Delivery $delivery): bool;

    public function save(Delivery $delivery): void;

    public function find(TenantContext $tenant, Uuid $id): ?Delivery;

    public function findForUpdate(TenantContext $tenant, Uuid $id): ?Delivery;

    /**
     * Pending deliveries whose next attempt is due, oldest first, across every
     * tenant — what the dispatcher queues.
     *
     * @return list<Delivery>
     */
    public function dueAt(DateTimeImmutable $now, int $limit): array;
}
