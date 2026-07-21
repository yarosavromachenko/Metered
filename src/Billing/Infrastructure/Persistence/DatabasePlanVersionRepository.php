<?php

declare(strict_types=1);

namespace Metered\Billing\Infrastructure\Persistence;

use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Metered\Billing\Domain\Period\BillingInterval;
use Metered\Billing\Domain\Plan\PlanVersion;
use Metered\Billing\Domain\Plan\PlanVersionRepository;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Infrastructure\Persistence\RowReader;
use stdClass;

final readonly class DatabasePlanVersionRepository implements PlanVersionRepository
{
    /** Microseconds kept: the query builder's own format stops at seconds. */
    private const string INSTANT = 'Y-m-d H:i:s.uP';

    public function __construct(private DatabaseManager $db) {}

    public function save(PlanVersion $version): void
    {
        $connection = $this->db->connection();

        $connection->transaction(function () use ($connection, $version): void {
            // The version row first without its publication, then the prices,
            // then the publication: the database refuses prices written under a
            // version that is already published, so the order is the rule.
            $connection->table('plan_versions')->upsert([
                'id' => $version->id->value,
                'organization_id' => $version->tenant->organizationId->value,
                'project_id' => $version->tenant->projectId->value,
                'plan_id' => $version->planId->value,
                'number' => $version->number,
                'currency' => $version->currency,
                'interval' => $version->interval->value,
                'created_at' => $version->createdAt->format(self::INSTANT),
                'published_at' => null,
                // Nothing on a version's row changes after it is drafted, but an
                // empty update list turns upsert into a plain insert, and saving
                // a draft a second time would collide with itself.
            ], ['id'], ['currency']);

            $connection->table('prices')->where('plan_version_id', $version->id->value)->delete();

            $rows = [];

            foreach ($version->prices as $position => $price) {
                $rows[] = [
                    'id' => $price->id->value,
                    'organization_id' => $version->tenant->organizationId->value,
                    'project_id' => $version->tenant->projectId->value,
                    'plan_version_id' => $version->id->value,
                    'position' => $position,
                ] + PriceColumns::from($price);
            }

            if ($rows !== []) {
                $connection->table('prices')->insert($rows);
            }

            if ($version->publishedAt instanceof DateTimeImmutable) {
                $connection->table('plan_versions')
                    ->where('id', $version->id->value)
                    ->update(['published_at' => $version->publishedAt->format(self::INSTANT)]);
            }
        });
    }

    public function find(TenantContext $tenant, Uuid $id): ?PlanVersion
    {
        $row = $this->scoped($tenant)->where('id', $id->value)->first();

        return $row instanceof stdClass ? $this->toVersion($row) : null;
    }

    public function listForPlan(TenantContext $tenant, Uuid $planId): array
    {
        $versions = [];

        foreach ($this->scoped($tenant)->where('plan_id', $planId->value)->orderByDesc('number')->get() as $row) {
            if ($row instanceof stdClass) {
                $versions[] = $this->toVersion($row);
            }
        }

        return $versions;
    }

    public function nextNumber(TenantContext $tenant, Uuid $planId): int
    {
        $latest = $this->scoped($tenant)->where('plan_id', $planId->value)->max('number');

        return ($latest === null ? 0 : RowReader::int($latest, 'number')) + 1;
    }

    private function scoped(TenantContext $tenant): Builder
    {
        return $this->db->connection()->table('plan_versions')
            ->where('project_id', $tenant->projectId->value)
            ->where('organization_id', $tenant->organizationId->value);
    }

    private function toVersion(stdClass $row): PlanVersion
    {
        $values = get_object_vars($row);
        $id = RowReader::string($values['id'] ?? null, 'id');

        $prices = [];

        foreach ($this->db->connection()->table('prices')->where('plan_version_id', $id)->orderBy('position')->get() as $price) {
            if ($price instanceof stdClass) {
                $prices[] = PriceColumns::toPrice(get_object_vars($price));
            }
        }

        return PlanVersion::restore(
            Uuid::fromString($id),
            new TenantContext(
                Uuid::fromString(RowReader::string($values['organization_id'] ?? null, 'organization_id')),
                Uuid::fromString(RowReader::string($values['project_id'] ?? null, 'project_id')),
            ),
            Uuid::fromString(RowReader::string($values['plan_id'] ?? null, 'plan_id')),
            RowReader::int($values['number'] ?? null, 'number'),
            RowReader::string($values['currency'] ?? null, 'currency'),
            BillingInterval::from(RowReader::string($values['interval'] ?? null, 'interval')),
            $prices,
            RowReader::instant($values['created_at'] ?? null, 'created_at'),
            RowReader::instantOrNull($values['published_at'] ?? null, 'published_at'),
        );
    }
}
