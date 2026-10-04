<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Port;

/**
 * There is no API for creating organizations, so this does what an operator does.
 */
interface TenantProvisioner
{
    /**
     * @param  bool  $demo  created as a demo organization, which demo:reset may purge
     */
    public function provision(string $organizationName, bool $demo = false): ProvisionedTenant;
}
