<?php

declare(strict_types=1);

namespace Metered\Billing\Infrastructure\Persistence;

use Illuminate\Database\DatabaseManager;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Application\Contract\TenantDataPurger;

/**
 * A purged tenant's catalog, customers and subscriptions, in the order the
 * schema allows.
 *
 * Phases refuse to lose their plan version and prices refuse to change on a
 * published one, so subscriptions go first and plans take their versions and
 * prices with them by cascade — by the time the cascade reaches a price, its
 * version is already gone and the price trigger has nothing to protect.
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
