<?php

declare(strict_types=1);

namespace Metered\Billing\Infrastructure\Persistence;

use Illuminate\Database\DatabaseManager;
use Metered\Billing\Domain\Customer;
use Metered\Billing\Domain\CustomerReference;
use Metered\Billing\Domain\CustomerRepository;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Infrastructure\Persistence\RowReader;
use stdClass;

final readonly class DatabaseCustomerRepository implements CustomerRepository
{
    public function __construct(private DatabaseManager $db) {}

    public function save(Customer $customer): void
    {
        $this->db->connection()->table('customers')->upsert([
            'id' => $customer->id->value,
            'organization_id' => $customer->tenant->organizationId->value,
            'project_id' => $customer->tenant->projectId->value,
            'reference' => $customer->reference->value,
            'name' => $customer->name,
            'created_at' => $customer->registeredAt,
            // The reference never changes.
        ], ['id'], ['name']);
    }

    public function find(TenantContext $tenant, Uuid $id): ?Customer
    {
        return $this->first($tenant, ['id' => $id->value]);
    }

    public function findByReference(TenantContext $tenant, CustomerReference $reference): ?Customer
    {
        return $this->first($tenant, ['reference' => $reference->value]);
    }

    public function listFor(TenantContext $tenant): array
    {
        $rows = $this->db->connection()->table('customers')
            ->where('project_id', $tenant->projectId->value)
            ->where('organization_id', $tenant->organizationId->value)
            ->orderBy('reference')
            ->get();

        $customers = [];

        foreach ($rows as $row) {
            if ($row instanceof stdClass) {
                $customers[] = $this->toCustomer($row);
            }
        }

        return $customers;
    }

    /**
     * @param  array<string, string>  $conditions
     */
    private function first(TenantContext $tenant, array $conditions): ?Customer
    {
        $row = $this->db->connection()->table('customers')
            ->where('project_id', $tenant->projectId->value)
            ->where('organization_id', $tenant->organizationId->value)
            ->where($conditions)
            ->first();

        return $row instanceof stdClass ? $this->toCustomer($row) : null;
    }

    private function toCustomer(stdClass $row): Customer
    {
        $values = get_object_vars($row);

        return Customer::register(
            Uuid::fromString(RowReader::string($values['id'] ?? null, 'id')),
            new TenantContext(
                Uuid::fromString(RowReader::string($values['organization_id'] ?? null, 'organization_id')),
                Uuid::fromString(RowReader::string($values['project_id'] ?? null, 'project_id')),
            ),
            CustomerReference::fromString(RowReader::string($values['reference'] ?? null, 'reference')),
            RowReader::string($values['name'] ?? null, 'name'),
            RowReader::instant($values['created_at'] ?? null, 'created_at'),
        );
    }
}
