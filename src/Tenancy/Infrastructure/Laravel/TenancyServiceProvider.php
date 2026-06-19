<?php

declare(strict_types=1);

namespace Metered\Tenancy\Infrastructure\Laravel;

use Illuminate\Support\ServiceProvider;
use Metered\Tenancy\Domain\ApiKeyRepository;
use Metered\Tenancy\Domain\OrganizationRepository;
use Metered\Tenancy\Domain\ProjectRepository;
use Metered\Tenancy\Infrastructure\Persistence\DatabaseApiKeyRepository;
use Metered\Tenancy\Infrastructure\Persistence\DatabaseOrganizationRepository;
use Metered\Tenancy\Infrastructure\Persistence\DatabaseProjectRepository;

/**
 * Wires the tenant model's ports to their database adapters.
 *
 * As in the shared kernel, the module owns its own wiring: everything Tenancy
 * needs in order to work travels with Tenancy rather than accumulating in
 * app/Providers.
 */
final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OrganizationRepository::class, DatabaseOrganizationRepository::class);
        $this->app->singleton(ProjectRepository::class, DatabaseProjectRepository::class);
        $this->app->singleton(ApiKeyRepository::class, DatabaseApiKeyRepository::class);
    }
}
