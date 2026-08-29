<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Contract;

use Metered\Shared\Domain\Identifier\Uuid;

/**
 * What a module holds for one organization, removed when that organization
 * is purged.
 *
 * Tenancy owns the organization but not the tables beneath it, and it may
 * not reach into them (ADR-0001). Each module therefore names its own rows:
 * the purge is a list of explicit deletes, one module at a time, rather than
 * a cascade from `organizations` that the invoicing triggers would refuse and
 * that nobody could read off the schema (ADR-0008).
 *
 * The organization's projects are handed over with it, because every tenant
 * table is indexed by project: a delete by organization alone would read
 * the whole of `usage_events` to find one demo's rows.
 *
 * Implementations run inside the purge's transaction and must delete only
 * what their own module owns. They are called in the reverse of the order
 * their modules are registered in: a module is registered after the modules
 * it builds on, so its rows go first and nothing is left pointing at a row
 * that is already gone.
 */
interface TenantDataPurger
{
    public const string TAG = 'metered.tenant_data_purgers';

    /**
     * @param  list<Uuid>  $projectIds  every project of the organization
     */
    public function purgeOrganization(Uuid $organizationId, array $projectIds): void;
}
