<?php

declare(strict_types=1);

namespace Metered\Billing\Domain;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

interface MeterRepository
{
    public function save(Meter $meter): void;

    public function find(TenantContext $tenant, Uuid $id): ?Meter;

    /**
     * The lookup ingestion lives on: an event carries a code, and the consumer
     * needs the meter it names before it can write anything.
     */
    public function findByCode(TenantContext $tenant, MeterCode $code): ?Meter;

    /**
     * @return list<Meter>
     */
    public function listFor(TenantContext $tenant): array;
}
