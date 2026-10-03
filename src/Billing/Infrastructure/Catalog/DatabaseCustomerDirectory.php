<?php

declare(strict_types=1);

namespace Metered\Billing\Infrastructure\Catalog;

use Metered\Billing\Application\Contract\CustomerDescriptor;
use Metered\Billing\Application\Contract\CustomerDirectory;
use Metered\Billing\Domain\Customer;
use Metered\Billing\Domain\CustomerReference;
use Metered\Billing\Domain\CustomerRepository;
use Metered\Billing\Domain\Exception\InvalidCustomerReference;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * Returns null for an invalid reference instead of throwing.
 */
final readonly class DatabaseCustomerDirectory implements CustomerDirectory
{
    public function __construct(private CustomerRepository $customers) {}

    public function find(TenantContext $tenant, string $reference): ?CustomerDescriptor
    {
        try {
            $parsed = CustomerReference::fromString($reference);
        } catch (InvalidCustomerReference) {
            return null;
        }

        $customer = $this->customers->findByReference($tenant, $parsed);

        return $customer instanceof Customer
            ? new CustomerDescriptor($customer->id, $customer->reference->value)
            : null;
    }
}
