<?php

declare(strict_types=1);

namespace Metered\Tenancy\Infrastructure\Laravel;

use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Metered\Tenancy\Application\Authentication\ApiKeyAuthenticator;
use Metered\Tenancy\Application\Authorization\PermissionGuard;
use Metered\Tenancy\Application\Contract\Authorizer;
use Metered\Tenancy\Application\Identity\UserAccounts;
use Metered\Tenancy\Domain\ApiKeyRepository;
use Metered\Tenancy\Domain\MembershipRepository;
use Metered\Tenancy\Domain\OrganizationRepository;
use Metered\Tenancy\Domain\ProjectRepository;
use Metered\Tenancy\Infrastructure\Eloquent\EloquentUserAccounts;
use Metered\Tenancy\Infrastructure\Persistence\CachingApiKeyRepository;
use Metered\Tenancy\Infrastructure\Persistence\DatabaseApiKeyRepository;
use Metered\Tenancy\Infrastructure\Persistence\DatabaseMembershipRepository;
use Metered\Tenancy\Infrastructure\Persistence\DatabaseOrganizationRepository;
use Metered\Tenancy\Infrastructure\Persistence\DatabaseProjectRepository;
use Metered\Tenancy\Presentation\Console\CreateOrganizationCommand;
use Metered\Tenancy\Presentation\Filament\Components\ProjectSwitcher;
use Metered\Tenancy\Presentation\Http\Middleware\ThrottleApiKey;
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
        $this->app->singleton(MembershipRepository::class, DatabaseMembershipRepository::class);
        $this->app->singleton(UserAccounts::class, EloquentUserAccounts::class);
        // The answer other modules ask for. They hold the contract; the guard
        // that reads memberships is Tenancy's business and stays here.
        $this->app->singleton(Authorizer::class, PermissionGuard::class);
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

        $this->app->singleton(
            ThrottleApiKey::class,
            static fn(Application $app): ThrottleApiKey => new ThrottleApiKey(
                $app->make(RateLimiter::class),
                self::configInt($app, 'metered.api_keys.rate_limit_per_minute', 600),
            ),
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([CreateOrganizationCommand::class]);
        }

        // The module carries its own views and its own piece of the panel
        // chrome. The panel shell in src/Admin never learns that Tenancy has a
        // switcher; it renders whatever the modules have registered.
        $this->loadViewsFrom(base_path('src/Tenancy/Presentation/Filament/views'), 'tenancy');

        Livewire::component('tenancy.project-switcher', ProjectSwitcher::class);

        FilamentView::registerRenderHook(
            PanelsRenderHook::TOPBAR_START,
            static fn(): string => Blade::render('@livewire(\'tenancy.project-switcher\')'),
        );
    }

    private static function configInt(Application $app, string $key, int $default): int
    {
        $value = $app->make('config')->get($key);

        return is_int($value) ? $value : $default;
    }
}
