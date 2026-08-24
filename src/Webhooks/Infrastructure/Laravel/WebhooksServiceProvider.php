<?php

declare(strict_types=1);

namespace Metered\Webhooks\Infrastructure\Laravel;

use GuzzleHttp\Client;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Metered\Shared\Application\Transaction\Transactions;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Infrastructure\Laravel\SharedServiceProvider;
use Metered\Tenancy\Application\Contract\Authorizer;
use Metered\Webhooks\Application\Command\EndpointAudit;
use Metered\Webhooks\Application\Command\ReconfigureEndpointHandler;
use Metered\Webhooks\Application\Command\RegisterEndpointHandler;
use Metered\Webhooks\Application\Command\RotateEndpointSecretHandler;
use Metered\Webhooks\Application\Delivery\AttemptDeliveryHandler;
use Metered\Webhooks\Application\Delivery\FanOutWebhookEvent;
use Metered\Webhooks\Application\Delivery\Jitter;
use Metered\Webhooks\Application\Delivery\WebhookTransport;
use Metered\Webhooks\Domain\Delivery\AttemptLog;
use Metered\Webhooks\Domain\Delivery\DeliveryRepository;
use Metered\Webhooks\Domain\Endpoint\EndpointRepository;
use Metered\Webhooks\Infrastructure\Http\GuardedTransport;
use Metered\Webhooks\Infrastructure\Http\RandomJitter;
use Metered\Webhooks\Infrastructure\Http\Resolver;
use Metered\Webhooks\Infrastructure\Http\SystemResolver;
use Metered\Webhooks\Infrastructure\Persistence\DatabaseAttemptLog;
use Metered\Webhooks\Infrastructure\Persistence\DatabaseDeliveryRepository;
use Metered\Webhooks\Infrastructure\Persistence\DatabaseEndpointRepository;
use Metered\Webhooks\Presentation\Console\DispatchDeliveriesCommand;
use Psr\Clock\ClockInterface;

/**
 * Wires webhooks: the repositories, the guarded transport — the only HTTP
 * client pointed at a tenant's URL — and the fan-out that turns integration
 * events into deliveries.
 */
final class WebhooksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EndpointRepository::class, DatabaseEndpointRepository::class);
        $this->app->singleton(DeliveryRepository::class, DatabaseDeliveryRepository::class);
        $this->app->singleton(AttemptLog::class, DatabaseAttemptLog::class);
        $this->app->singleton(Resolver::class, SystemResolver::class);
        $this->app->singleton(Jitter::class, RandomJitter::class);

        // A client of its own, with nothing inherited: no base URI, no
        // default retries, no middleware that might follow a redirect.
        $this->app->singleton(WebhookTransport::class, static fn(Application $app): GuardedTransport => new GuardedTransport(
            new Client(),
            $app->make(Resolver::class),
        ));

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

        $this->app->bind(AttemptDeliveryHandler::class, static fn(Application $app): AttemptDeliveryHandler => new AttemptDeliveryHandler(
            $app->make(DeliveryRepository::class),
            $app->make(EndpointRepository::class),
            $app->make(AttemptLog::class),
            $app->make(WebhookTransport::class),
            $app->make(Transactions::class),
            $app->make(Jitter::class),
            $app->make(ClockInterface::class),
            self::configInt($app, 'metered.webhooks.breaker_threshold', 5),
            self::configInt($app, 'metered.webhooks.breaker_cooldown_seconds', 300),
            self::configInt($app, 'metered.webhooks.lease_seconds', 60),
        ));

        $this->app->tag([FanOutWebhookEvent::class], SharedServiceProvider::HANDLER_TAG);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([DispatchDeliveriesCommand::class]);
        }
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
