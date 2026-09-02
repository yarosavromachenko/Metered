<?php

declare(strict_types=1);

namespace Metered\Simulation\Infrastructure\Laravel;

use Illuminate\Contracts\Console\Kernel;
use Metered\Simulation\Application\Port\ProvisionedTenant;
use Metered\Simulation\Application\Port\TenantProvisioner;
use RuntimeException;

/**
 * `org:create --json`, exactly as an operator would run it. The simulation
 * may not reach into Tenancy (ADR-0001), and a console command is a public
 * interface as much as a route is.
 */
final readonly class ConsoleTenantProvisioner implements TenantProvisioner
{
    public function __construct(private Kernel $console) {}

    public function provision(string $organizationName): ProvisionedTenant
    {
        if ($this->console->call('org:create', ['name' => $organizationName, '--json' => true]) !== 0) {
            throw new RuntimeException('org:create failed: ' . trim($this->console->output()));
        }

        $result = json_decode(trim($this->console->output()), true);

        $organizationId = is_array($result) && is_array($result['organization'] ?? null) ? $result['organization']['id'] ?? null : null;
        $slug = is_array($result) && is_array($result['organization'] ?? null) ? $result['organization']['slug'] ?? null : null;
        $projectId = is_array($result) && is_array($result['project'] ?? null) ? $result['project']['id'] ?? null : null;
        $token = is_array($result) && is_array($result['key'] ?? null) ? $result['key']['secret'] ?? null : null;

        if (! is_string($organizationId) || ! is_string($slug) || ! is_string($projectId) || ! is_string($token)) {
            throw new RuntimeException('org:create --json printed something unexpected.');
        }

        return new ProvisionedTenant($organizationId, $slug, $projectId, $token);
    }
}
