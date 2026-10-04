<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Persistence;

use Illuminate\Database\DatabaseManager;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Application\Contract\TenantDataPurger;

/**
 * Deletes by project id (the leading index column). `usage_events` has no
 * foreign key to projects (ADR-0002), so nothing cascades.
 */
final readonly class DatabaseUsagePurger implements TenantDataPurger
{
    public function __construct(private DatabaseManager $db) {}

    public function purgeOrganization(Uuid $organizationId, array $projectIds): void
    {
        $projects = array_map(static fn(Uuid $id): string => $id->value, $projectIds);

        if ($projects === []) {
            return;
        }

        foreach (['usage_event_rejections', 'usage_aggregates', 'usage_events'] as $table) {
            $this->db->connection()->table($table)->whereIn('project_id', $projects)->delete();
        }
    }
}
