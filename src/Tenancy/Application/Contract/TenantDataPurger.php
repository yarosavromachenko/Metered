<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Contract;

use Metered\Shared\Domain\Identifier\Uuid;

/**
 * Each module deletes its own rows of a purged organization, inside the
 * purge's transaction (ADR-0001, ADR-0008). Project ids are passed because
 * tenant tables are indexed by project. Purgers run in reverse module
 * registration order, so dependent rows go first.
 */
interface TenantDataPurger
{
    public const string TAG = 'metered.tenant_data_purgers';

    /**
     * @param  list<Uuid>  $projectIds  every project of the organization
     */
    public function purgeOrganization(Uuid $organizationId, array $projectIds): void;
}
