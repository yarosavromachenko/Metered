<?php

declare(strict_types=1);

namespace Metered\Billing\Infrastructure\Persistence;

use Illuminate\Database\DatabaseManager;
use Metered\Billing\Domain\Meter;
use Metered\Billing\Domain\MeterCode;
use Metered\Billing\Domain\MeterRepository;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Metering\Aggregation;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Infrastructure\Persistence\RowReader;
use stdClass;

final readonly class DatabaseMeterRepository implements MeterRepository
{
    public function __construct(private DatabaseManager $db) {}

    public function save(Meter $meter): void
    {
        $this->db->connection()->table('meters')->upsert([
            'id' => $meter->id->value,
            'organization_id' => $meter->tenant->organizationId->value,
            'project_id' => $meter->tenant->projectId->value,
            'code' => $meter->code->value,
            'name' => $meter->name,
            'aggregation' => $meter->aggregation->value,
            'created_at' => $meter->definedAt,
            // Code and aggregation never change.
        ], ['id'], ['name']);
    }

    public function find(TenantContext $tenant, Uuid $id): ?Meter
    {
        return $this->first($tenant, ['id' => $id->value]);
    }

    public function findByCode(TenantContext $tenant, MeterCode $code): ?Meter
    {
        return $this->first($tenant, ['code' => $code->value]);
    }

    public function listFor(TenantContext $tenant): array
    {
        $rows = $this->db->connection()->table('meters')
            ->where('project_id', $tenant->projectId->value)
            ->where('organization_id', $tenant->organizationId->value)
            ->orderBy('code')
            ->get();

        $meters = [];

        foreach ($rows as $row) {
            if ($row instanceof stdClass) {
                $meters[] = $this->toMeter($row);
            }
        }

        return $meters;
    }

    /**
     * @param  array<string, string>  $conditions
     */
    private function first(TenantContext $tenant, array $conditions): ?Meter
    {
        $row = $this->db->connection()->table('meters')
            ->where('project_id', $tenant->projectId->value)
            ->where('organization_id', $tenant->organizationId->value)
            ->where($conditions)
            ->first();

        return $row instanceof stdClass ? $this->toMeter($row) : null;
    }

    private function toMeter(stdClass $row): Meter
    {
        $values = get_object_vars($row);

        return Meter::define(
            Uuid::fromString(RowReader::string($values['id'] ?? null, 'id')),
            new TenantContext(
                Uuid::fromString(RowReader::string($values['organization_id'] ?? null, 'organization_id')),
                Uuid::fromString(RowReader::string($values['project_id'] ?? null, 'project_id')),
            ),
            MeterCode::fromString(RowReader::string($values['code'] ?? null, 'code')),
            RowReader::string($values['name'] ?? null, 'name'),
            Aggregation::from(RowReader::string($values['aggregation'] ?? null, 'aggregation')),
            RowReader::instant($values['created_at'] ?? null, 'created_at'),
        );
    }
}
