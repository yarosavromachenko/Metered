<?php

declare(strict_types=1);

namespace Metered\Webhooks\Infrastructure\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Tenancy\Application\Contract\Authorizer;
use Metered\Webhooks\Application\Command\EndpointAudit;
use Metered\Webhooks\Application\Command\ReconfigureEndpointHandler;
use Metered\Webhooks\Application\Command\RegisterEndpointHandler;
use Metered\Webhooks\Application\Command\RotateEndpointSecretHandler;
use Metered\Webhooks\Domain\Delivery\DeliveryRepository;
use Metered\Webhooks\Domain\Endpoint\EndpointRepository;
use Metered\Webhooks\Infrastructure\Persistence\DatabaseDeliveryRepository;
use Metered\Webhooks\Infrastructure\Persistence\DatabaseEndpointRepository;
use Psr\Clock\ClockInterface;

/**
 * Wires webhooks: the repositories and the endpoint commands.
 */
final class WebhooksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EndpointRepository::class, DatabaseEndpointRepository::class);
        $this->app->singleton(DeliveryRepository::class, DatabaseDeliveryRepository::class);

        $this->app->bind(RegisterEndpointHandler::class, static fn(Application $app): RegisterEndpointHandler => new RegisterEndpointHandler(
            $app->make(EndpointRepository::class),
            $app->make(Authorizer::class),
            $app->make(IdentifierGenerator::class),
            $app->make(ClockInterface::class),
            $app->make(EndpointAudit::class),
            self::configBool($app, 'metered.webhooks.allow_http'),
        ));

        $this->app->bind(ReconfigureEndpointHandler::class, static fn(Application $app): ReconfigureEndpointHandler => new ReconfigureEndpointHandler(
            $app->make(EndpointRepository::class),
            $app->make(Authorizer::class),
            $app->make(EndpointAudit::class),
            self::configBool($app, 'metered.webhooks.allow_http'),
        ));

        $this->app->bind(RotateEndpointSecretHandler::class, static fn(Application $app): RotateEndpointSecretHandler => new RotateEndpointSecretHandler(
            $app->make(EndpointRepository::class),
            $app->make(Authorizer::class),
            $app->make(ClockInterface::class),
            $app->make(EndpointAudit::class),
            self::configInt($app, 'metered.webhooks.rotation_grace_seconds', 86_400),
        ));
    }

    private static function configInt(Application $app, string $key, int $default): int
    {
        $value = $app->make('config')->get($key);

        return is_int($value) ? $value : $default;
    }

    private static function configBool(Application $app, string $key): bool
    {
        return $app->make('config')->get($key) === true;
    }
}
