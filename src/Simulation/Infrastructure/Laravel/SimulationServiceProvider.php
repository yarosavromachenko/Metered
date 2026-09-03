<?php

declare(strict_types=1);

namespace Metered\Simulation\Infrastructure\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Simulation\Application\Port\ApiConnector;
use Metered\Simulation\Application\Port\TenantProvisioner;
use Metered\Simulation\Application\Port\WebhookInbox;
use Metered\Simulation\Infrastructure\Http\HttpApiConnector;
use Metered\Simulation\Infrastructure\Http\ReceiverInbox;
use Metered\Simulation\Presentation\Console\SeedCommand;

/**
 * The simulation's wiring. The module is removed from the production image,
 * and bootstrap/providers.php lists this provider only when the class exists;
 * where it does exist, it still registers nothing outside local, demo and
 * testing, because what it does — creating tenants, flooding the API — is
 * never something a real installation should be one typo away from.
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
    }

    public function boot(): void
    {
        if ($this->app->environment(self::ENVIRONMENTS) && $this->app->runningInConsole()) {
            $this->commands([SeedCommand::class]);
        }
    }

    private static function configString(Application $app, string $key, string $default): string
    {
        $value = $app->make('config')->get($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
