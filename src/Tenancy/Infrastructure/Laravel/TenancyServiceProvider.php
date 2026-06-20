<?php

declare(strict_types=1);

namespace Metered\Tenancy\Infrastructure\Laravel;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Metered\Tenancy\Application\Authentication\ApiKeyAuthenticator;
use Metered\Tenancy\Domain\ApiKeyRepository;
use Metered\Tenancy\Domain\OrganizationRepository;
use Metered\Tenancy\Domain\ProjectRepository;
use Metered\Tenancy\Infrastructure\Persistence\CachingApiKeyRepository;
use Metered\Tenancy\Infrastructure\Persistence\DatabaseApiKeyRepository;
use Metered\Tenancy\Infrastructure\Persistence\DatabaseOrganizationRepository;
use Metered\Tenancy\Infrastructure\Persistence\DatabaseProjectRepository;
use Psr\Clock\ClockInterface;

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
        // The port everyone asks for is the cached one; the database
        // repository is what it decorates. Nothing else in the system needs
        // to know which of the two it is talking to.
        $this->app->singleton(
            ApiKeyRepository::class,
            static fn(Application $app): ApiKeyRepository => new CachingApiKeyRepository(
                $app->make(DatabaseApiKeyRepository::class),
                $app->make(CacheFactory::class)->store(),
                self::configInt($app, 'metered.api_keys.cache_ttl_seconds', 30),
            ),
        );

        $this->app->singleton(
            ApiKeyAuthenticator::class,
            static fn(Application $app): ApiKeyAuthenticator => new ApiKeyAuthenticator(
                $app->make(ApiKeyRepository::class),
                $app->make(ClockInterface::class),
                self::configInt($app, 'metered.api_keys.usage_recording_interval_seconds', 300),
            ),
        );
    }

    private static function configInt(Application $app, string $key, int $default): int
    {
        $value = $app->make('config')->get($key);

        return is_int($value) ? $value : $default;
    }
}
