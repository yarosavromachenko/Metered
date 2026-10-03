<?php

declare(strict_types=1);

namespace Metered\Billing\Infrastructure\Persistence;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Metered\Billing\Domain\Plan\Plan;
use Metered\Billing\Domain\Plan\PlanCode;
use Metered\Billing\Domain\Plan\PlanRepository;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Infrastructure\Persistence\RowReader;
use stdClass;

final readonly class DatabasePlanRepository implements PlanRepository
{
    /** Keeps microseconds, which the query builder's format drops. */
    private const string INSTANT = 'Y-m-d H:i:s.uP';

    public function __construct(private DatabaseManager $db) {}

    public function save(Plan $plan): void
    {
        $this->db->connection()->table('plans')->upsert([
            'id' => $plan->id->value,
            'organization_id' => $plan->tenant->organizationId->value,
            'project_id' => $plan->tenant->projectId->value,
            'code' => $plan->code->value,
            'name' => $plan->name,
            'created_at' => $plan->createdAt->format(self::INSTANT),
            // The code never changes.
        ], ['id'], ['name']);
    }

    public function find(TenantContext $tenant, Uuid $id): ?Plan
    {
        return $this->first($tenant, ['id' => $id->value]);
    }

    public function findByCode(TenantContext $tenant, PlanCode $code): ?Plan
    {
        return $this->first($tenant, ['code' => $code->value]);
    }

    public function listFor(TenantContext $tenant): array
    {
        $plans = [];

        foreach ($this->scoped($tenant)->orderBy('code')->get() as $row) {
            if ($row instanceof stdClass) {
                $plans[] = $this->toPlan($row);
            }
        }

        return $plans;
    }

    /**
     * @param array<string, string> $conditions
     */
    private function first(TenantContext $tenant, array $conditions): ?Plan
    {
        $row = $this->scoped($tenant)->where($conditions)->first();

        return $row instanceof stdClass ? $this->toPlan($row) : null;
    }

    private function scoped(TenantContext $tenant): Builder
    {
        return $this->db->connection()->table('plans')
            ->where('project_id', $tenant->projectId->value)
            ->where('organization_id', $tenant->organizationId->value);
    }

    private function toPlan(stdClass $row): Plan
    {
        $values = get_object_vars($row);

        return Plan::create(
            Uuid::fromString(RowReader::string($values['id'] ?? null, 'id')),
            new TenantContext(
                Uuid::fromString(RowReader::string($values['organization_id'] ?? null, 'organization_id')),
                Uuid::fromString(RowReader::string($values['project_id'] ?? null, 'project_id')),
            ),
            PlanCode::fromString(RowReader::string($values['code'] ?? null, 'code')),
            RowReader::string($values['name'] ?? null, 'name'),
            RowReader::instant($values['created_at'] ?? null, 'created_at'),
        );
    }
}
