<?php

declare(strict_types=1);

namespace Metered\Webhooks\Infrastructure\Persistence;

use Illuminate\Database\DatabaseManager;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Application\Contract\TenantDataPurger;

/**
 * Deliveries, then endpoints; see DatabaseEndpointRepository::remove().
 */
final readonly class DatabaseWebhooksPurger implements TenantDataPurger
{
    public function __construct(private DatabaseManager $db) {}

    public function purgeOrganization(Uuid $organizationId, array $projectIds): void
    {
        $projects = array_map(static fn(Uuid $id): string => $id->value, $projectIds);

        if ($projects === []) {
            return;
        }

        $this->db->connection()->table('webhook_deliveries')->whereIn('project_id', $projects)->delete();
        $this->db->connection()->table('webhook_endpoints')->whereIn('project_id', $projects)->delete();
    }
}
