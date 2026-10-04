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
use Metered\Shared\Application\Audit\AuditLogger;
use Metered\Shared\Application\Transaction\Transactions;
use Metered\Tenancy\Application\Authentication\ApiKeyAuthenticator;
use Metered\Tenancy\Application\Authorization\PermissionGuard;
use Metered\Tenancy\Application\Command\IssueApiKeyHandler;
use Metered\Tenancy\Application\Command\PurgeDemoOrganizationHandler;
use Metered\Tenancy\Application\Command\PurgeIdleDemosHandler;
use Metered\Tenancy\Application\Command\ResetDemoOrganizationHandler;
use Metered\Tenancy\Application\Contract\Authorizer;
use Metered\Tenancy\Application\Contract\PanelScope as PanelScopeContract;
use Metered\Tenancy\Application\Contract\ProjectDirectory;
use Metered\Tenancy\Application\Contract\TenantDataPurger;
use Metered\Tenancy\Application\Identity\UserAccounts;
use Metered\Tenancy\Domain\ApiKeyRepository;
use Metered\Tenancy\Domain\MembershipRepository;
use Metered\Tenancy\Domain\OrganizationRepository;
use Metered\Tenancy\Domain\ProjectRepository;
use Metered\Tenancy\Domain\Slug;
use Metered\Tenancy\Infrastructure\Eloquent\EloquentUserAccounts;
use Metered\Tenancy\Infrastructure\Persistence\CachingApiKeyRepository;
use Metered\Tenancy\Infrastructure\Persistence\DatabaseApiKeyRepository;
use Metered\Tenancy\Infrastructure\Persistence\DatabaseMembershipRepository;
use Metered\Tenancy\Infrastructure\Persistence\DatabaseOrganizationRepository;
use Metered\Tenancy\Infrastructure\Persistence\DatabaseProjectDirectory;
use Metered\Tenancy\Infrastructure\Persistence\DatabaseProjectRepository;
use Metered\Tenancy\Presentation\Console\AddMemberCommand;
use Metered\Tenancy\Presentation\Console\CreateOrganizationCommand;
use Metered\Tenancy\Presentation\Console\PurgeIdleDemosCommand;
use Metered\Tenancy\Presentation\Console\ResetDemosCommand;
use Metered\Tenancy\Presentation\Filament\Components\ProjectSwitcher;
use Metered\Tenancy\Presentation\Filament\PanelScope;
use Metered\Tenancy\Presentation\Http\Middleware\ThrottleApiKey;
use Psr\Clock\ClockInterface;

final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OrganizationRepository::class, DatabaseOrganizationRepository::class);
        $this->app->singleton(ProjectRepository::class, DatabaseProjectRepository::class);
        $this->app->singleton(MembershipRepository::class, DatabaseMembershipRepository::class);
        $this->app->singleton(UserAccounts::class, EloquentUserAccounts::class);
        $this->app->singleton(Authorizer::class, PermissionGuard::class);
        $this->app->singleton(ProjectDirectory::class, DatabaseProjectDirectory::class);
        // Not a singleton: it reads the current session (Octane).
        $this->app->bind(PanelScopeContract::class, PanelScope::class);
        // The cache decorates the database repository.
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

        // Reverse registration order (see TenantDataPurger).
        $this->app->bind(
            PurgeDemoOrganizationHandler::class,
            static fn(Application $app): PurgeDemoOrganizationHandler => new PurgeDemoOrganizationHandler(
                $app->make(OrganizationRepository::class),
                $app->make(ProjectRepository::class),
                $app->make(MembershipRepository::class),
                $app->make(UserAccounts::class),
                self::purgers($app),
                $app->make(Transactions::class),
                $app->make(AuditLogger::class),
                $app->make(ClockInterface::class),
            ),
        );

        $this->app->bind(
            ResetDemoOrganizationHandler::class,
            static fn(Application $app): ResetDemoOrganizationHandler => new ResetDemoOrganizationHandler(
                $app->make(OrganizationRepository::class),
                $app->make(ProjectRepository::class),
                $app->make(Authorizer::class),
                $app->make(IssueApiKeyHandler::class),
                self::purgers($app),
                $app->make(Transactions::class),
                $app->make(AuditLogger::class),
                $app->make(ClockInterface::class),
            ),
        );

        $this->app->bind(
            PurgeIdleDemosHandler::class,
            static fn(Application $app): PurgeIdleDemosHandler => new PurgeIdleDemosHandler(
                $app->make(OrganizationRepository::class),
                $app->make(PurgeDemoOrganizationHandler::class),
                $app->make(ClockInterface::class),
                self::configInt($app, 'metered.demo.idle_days', 7) * 86_400,
                is_string($showcase = $app->make('config')->get('metered.demo.showcase')) && $showcase !== '' ? Slug::fromString($showcase) : null,
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
        DemoMode::assertAllowed(
            $this->app->make('config')->get('metered.demo.enabled') === true,
            (string) $this->app->environment(),
        );

        if ($this->app->runningInConsole()) {
            $this->commands([CreateOrganizationCommand::class, AddMemberCommand::class, PurgeIdleDemosCommand::class, ResetDemosCommand::class]);
        }

        // The project switcher is registered as a render hook.
        $this->loadViewsFrom(base_path('src/Tenancy/Presentation/Filament/views'), 'tenancy');

        Livewire::component('tenancy.project-switcher', ProjectSwitcher::class);

        FilamentView::registerRenderHook(
            PanelsRenderHook::TOPBAR_START,
            static fn(): string => Blade::render('@livewire(\'tenancy.project-switcher\')'),
        );

        // Demo only: the sign-in page shows the showcase login (ADR-0016).
        if ($this->app->make('config')->get('metered.demo.enabled') === true) {
            FilamentView::registerRenderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                fn(): string => view('tenancy::filament.showcase-login', [
                    'email' => $this->app->make('config')->get('metered.demo.showcase_login.email'),
                    'password' => $this->app->make('config')->get('metered.demo.showcase_login.password'),
                ])->render(),
            );
        }
    }

    /**
     * @return list<TenantDataPurger>
     */
    private static function purgers(Application $app): array
    {
        $purgers = [];

        foreach ($app->tagged(TenantDataPurger::TAG) as $purger) {
            if ($purger instanceof TenantDataPurger) {
                $purgers[] = $purger;
            }
        }

        return array_reverse($purgers);
    }

    private static function configInt(Application $app, string $key, int $default): int
    {
        $value = $app->make('config')->get($key);

        return is_int($value) ? $value : $default;
    }
}
