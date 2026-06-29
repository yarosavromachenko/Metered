<?php

declare(strict_types=1);

namespace Metered\Billing\Domain;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

interface CustomerRepository
{
    public function save(Customer $customer): void;

    public function find(TenantContext $tenant, Uuid $id): ?Customer;

    public function findByReference(TenantContext $tenant, CustomerReference $reference): ?Customer;

    /**
     * @return list<Customer>
     */
    public function listFor(TenantContext $tenant): array;
}
