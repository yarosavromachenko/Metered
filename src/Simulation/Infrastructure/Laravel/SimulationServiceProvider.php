<?php

declare(strict_types=1);

namespace Metered\Simulation\Infrastructure\Laravel;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Simulation\Application\Port\ApiConnector;
use Metered\Simulation\Application\Port\Disruption;
use Metered\Simulation\Application\Port\HistoryLoader;
use Metered\Simulation\Application\Port\Pacer;
use Metered\Simulation\Application\Port\PeriodCloser;
use Metered\Simulation\Application\Port\TenantProvisioner;
use Metered\Simulation\Application\Port\TimeMachine;
use Metered\Simulation\Application\Port\UsageAudit;
use Metered\Simulation\Application\Port\Waiter;
use Metered\Simulation\Application\Port\WebhookInbox;
use Metered\Simulation\Infrastructure\Http\HttpApiConnector;
use Metered\Simulation\Infrastructure\Http\ReceiverInbox;
use Metered\Simulation\Infrastructure\Persistence\CopyHistoryLoader;
use Metered\Simulation\Infrastructure\Queue\SeedDemoTenantJob;
use Metered\Simulation\Presentation\Console\ChaosCommand;
use Metered\Simulation\Presentation\Console\SeedCommand;
use Metered\Simulation\Presentation\Console\TimeTravelCommand;
use Metered\Simulation\Presentation\Console\TrafficCommand;
use Metered\Tenancy\Application\Contract\DemoDataRequested;

/**
 * Not in the production image; bootstrap/providers.php loads it only if the
 * class exists, and it registers nothing outside local, demo and testing.
 */
final class SimulationServiceProvider extends ServiceProvider
{
    /** @var list<string> */
    public const array ENVIRONMENTS = ['local', 'demo', 'testing'];

    public function register(): void
    {
        if (! $this->app->environment(self::ENVIRONMENTS)) {
            return;
        }

        $this->app->singleton(ApiConnector::class, static fn(Application $app): ApiConnector => new HttpApiConnector(
            $app->make(Factory::class),
            $app->make(IdentifierGenerator::class),
            self::configString($app, 'metered.simulation.api_url', 'http://app:8080'),
        ));

        $this->app->singleton(WebhookInbox::class, static fn(Application $app): WebhookInbox => new ReceiverInbox(
            $app->make(Factory::class),
            self::configString($app, 'metered.simulation.receiver_url', 'http://webhook-receiver:8080'),
        ));

        $this->app->singleton(TenantProvisioner::class, ConsoleTenantProvisioner::class);
        $this->app->singleton(PeriodCloser::class, ConsolePeriodCloser::class);
        $this->app->singleton(HistoryLoader::class, CopyHistoryLoader::class);
        $this->app->bind(Pacer::class, WallClockPacer::class);
        $this->app->singleton(TimeMachine::class, SharedClockTimeMachine::class);
        $this->app->singleton(Waiter::class, PollingWaiter::class);
        $this->app->singleton(UsageAudit::class, ConsoleUsageAudit::class);
        $this->app->singleton(Disruption::class, static fn(Application $app): Disruption => new ProcessDisruption(
            $app->make(RedisFactory::class),
            $app->make('config'),
            $app->basePath('artisan'),
        ));
    }

    public function boot(): void
    {
        // Demo sign-ups and resets get the small profile, queued.
        if ($this->app->environment(self::ENVIRONMENTS)) {
            $this->app->make(Dispatcher::class)->listen(
                DemoDataRequested::class,
                static fn(DemoDataRequested $request): mixed => dispatch(new SeedDemoTenantJob($request->organizationId, $request->token)),
            );
        }

        if ($this->app->environment(self::ENVIRONMENTS) && $this->app->runningInConsole()) {
            $this->commands([SeedCommand::class, TrafficCommand::class, TimeTravelCommand::class, ChaosCommand::class]);
        }
    }

    private static function configString(Application $app, string $key, string $default): string
    {
        $value = $app->make('config')->get($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
