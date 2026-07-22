<?php

declare(strict_types=1);

namespace Metered\Tenancy\Infrastructure\Persistence;

use Illuminate\Database\DatabaseManager;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Infrastructure\Persistence\RowReader;
use Metered\Tenancy\Application\Contract\ProjectDirectory;
use stdClass;

/**
 * The two ids of every project, and nothing else about them: this is what
 * operator tooling needs, and reading less means exposing less.
 */
final readonly class DatabaseProjectDirectory implements ProjectDirectory
{
    public function __construct(private DatabaseManager $db) {}

    public function all(): array
    {
        $rows = $this->db->connection()->table('projects')
            ->select(['id', 'organization_id'])
            ->orderBy('created_at')
            ->get();

        $tenants = [];

        foreach ($rows as $row) {
            if ($row instanceof stdClass) {
                $tenants[] = $this->toTenant($row);
            }
        }

        return $tenants;
    }

    public function find(Uuid $projectId): ?TenantContext
    {
        $row = $this->db->connection()->table('projects')
            ->select(['id', 'organization_id'])
            ->where('id', $projectId->value)
            ->first();

        return $row instanceof stdClass ? $this->toTenant($row) : null;
    }

    public function currencyOf(TenantContext $tenant): ?string
    {
        $currency = $this->db->connection()->table('projects')
            ->where('id', $tenant->projectId->value)
            ->where('organization_id', $tenant->organizationId->value)
            ->value('currency');

        return $currency === null ? null : RowReader::string($currency, 'currency');
    }

    private function toTenant(stdClass $row): TenantContext
    {
        $values = get_object_vars($row);

        return new TenantContext(
            Uuid::fromString(RowReader::string($values['organization_id'] ?? null, 'organization_id')),
            Uuid::fromString(RowReader::string($values['id'] ?? null, 'id')),
        );
    }
}
