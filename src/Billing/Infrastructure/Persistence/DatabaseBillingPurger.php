<?php

declare(strict_types=1);

namespace Metered\Billing\Infrastructure\Persistence;

use Illuminate\Database\DatabaseManager;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Application\Contract\TenantDataPurger;

/**
 * Subscriptions first (phases reference plan versions), then plans, whose
 * versions and prices cascade.
 */
final readonly class DatabaseBillingPurger implements TenantDataPurger
{
    public function __construct(private DatabaseManager $db) {}

    public function purgeOrganization(Uuid $organizationId, array $projectIds): void
    {
        $projects = array_map(static fn(Uuid $id): string => $id->value, $projectIds);

        if ($projects === []) {
            return;
        }

        foreach (['subscription_phases', 'subscriptions', 'plans', 'customers', 'meters'] as $table) {
            $this->db->connection()->table($table)->whereIn('project_id', $projects)->delete();
        }
    }
}
