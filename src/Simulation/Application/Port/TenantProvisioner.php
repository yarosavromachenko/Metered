<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Port;

/**
 * Creates the tenant a seed fills. There is no API for this — an
 * organization is created by an operator or by a sign-up — so the seed does
 * what an operator does.
 */
interface TenantProvisioner
{
    public function provision(string $organizationName): ProvisionedTenant;
}
