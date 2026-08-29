<?php

declare(strict_types=1);

namespace Metered\Webhooks\Infrastructure\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Metered\Shared\Application\Transaction\Transactions;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Infrastructure\Laravel\SharedServiceProvider;
use Metered\Tenancy\Application\Contract\Authorizer;
use Metered\Tenancy\Application\Contract\TenantDataPurger;
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
use Metered\Webhooks\Infrastructure\Http\TrustedDestination;
use Metered\Webhooks\Infrastructure\Persistence\DatabaseAttemptLog;
use Metered\Webhooks\Infrastructure\Persistence\DatabaseDeliveryRepository;
use Metered\Webhooks\Infrastructure\Persistence\DatabaseEndpointRepository;
use Metered\Webhooks\Infrastructure\Persistence\DatabaseWebhooksPurger;
use Metered\Webhooks\Presentation\Console\DispatchDeliveriesCommand;
use Metered\Webhooks\Presentation\Console\ReplayDeliveryCommand;
use Metered\Webhooks\Presentation\Http\DeleteEndpointController;
use Metered\Webhooks\Presentation\Http\ListDeliveriesController;
use Metered\Webhooks\Presentation\Http\ListEndpointsController;
use Metered\Webhooks\Presentation\Http\RegisterEndpointController;
use Metered\Webhooks\Presentation\Http\ReplayDeliveryController;
use Metered\Webhooks\Presentation\Http\RotateSecretController;
use Metered\Webhooks\Presentation\Http\UpdateEndpointController;
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
            $app->make(Resolver::class),
            trusted: self::trustedDestination($app),
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
        // Removed with a purged demo tenant, by the module that owns the rows.
        $this->app->tag([DatabaseWebhooksPurger::class], TenantDataPurger::TAG);
    }

    public function boot(): void
    {
        // Read now rather than at the first delivery: a trusted destination
        // outside local and demo stops the application from starting at all.
        self::trustedDestination($this->app);

        if ($this->app->runningInConsole()) {
            $this->commands([DispatchDeliveriesCommand::class, ReplayDeliveryCommand::class]);
        }

        // An admin key's, like the catalog: a key embedded in a client's
        // product reports usage and can reconfigure nothing. Writes take an
        // Idempotency-Key, so a retried request cannot register an endpoint
        // twice or rotate a secret twice.
        Route::middleware(['api', 'api-key:admin', 'throttle-api-key'])
            ->prefix('api/v1')
            ->group(static function (): void {
                Route::get('webhook-endpoints', ListEndpointsController::class)->name('webhooks.endpoints.list');
                Route::post('webhook-endpoints', RegisterEndpointController::class)->middleware('idempotent')->name('webhooks.endpoints.register');
                Route::patch('webhook-endpoints/{endpoint}', UpdateEndpointController::class)->middleware('idempotent')->name('webhooks.endpoints.update');
                Route::delete('webhook-endpoints/{endpoint}', DeleteEndpointController::class)->middleware('idempotent')->name('webhooks.endpoints.delete');
                Route::post('webhook-endpoints/{endpoint}/rotate-secret', RotateSecretController::class)->middleware('idempotent')->name('webhooks.endpoints.rotate');
                Route::get('webhook-deliveries', ListDeliveriesController::class)->name('webhooks.deliveries.list');
                Route::post('webhook-deliveries/{delivery}/replay', ReplayDeliveryController::class)->middleware('idempotent')->name('webhooks.deliveries.replay');
            });
    }

    private static function configInt(Application $app, string $key, int $default): int
    {
        $value = $app->make('config')->get($key);

        return is_int($value) ? $value : $default;
    }

    private static function trustedDestination(Application $app): ?TrustedDestination
    {
        $value = $app->make('config')->get('metered.webhooks.trusted_destination');

        return TrustedDestination::fromConfig(is_string($value) ? $value : null, $app->environment());
    }

    private static function configBool(Application $app, string $key): bool
    {
        return $app->make('config')->get($key) === true;
    }
}
