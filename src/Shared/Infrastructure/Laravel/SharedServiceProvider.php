<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\RequestTerminated;
use Metered\Shared\Application\Audit\AuditLogger;
use Metered\Shared\Application\Audit\ChainVerifier;
use Metered\Shared\Application\Health\Readiness;
use Metered\Shared\Application\Health\ReadinessCheck;
use Metered\Shared\Application\Idempotency\IdempotencyStore;
use Metered\Shared\Application\Inbox\InboxGuard;
use Metered\Shared\Application\Inbox\IntegrationEventHandler;
use Metered\Shared\Application\Metrics\GaugeSource;
use Metered\Shared\Application\Metrics\Metrics;
use Metered\Shared\Application\Outbox\OutboxPublisher;
use Metered\Shared\Application\Outbox\OutboxWriter;
use Metered\Shared\Application\Transaction\Transactions;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Infrastructure\Audit\DatabaseAuditLogger;
use Metered\Shared\Infrastructure\Audit\DatabaseChainVerifier;
use Metered\Shared\Infrastructure\Clock\ClockOffset;
use Metered\Shared\Infrastructure\Clock\SystemClock;
use Metered\Shared\Infrastructure\Clock\TravellingClock;
use Metered\Shared\Infrastructure\Health\DatabaseCheck;
use Metered\Shared\Infrastructure\Health\RedisCheck;
use Metered\Shared\Infrastructure\Idempotency\DatabaseIdempotencyStore;
use Metered\Shared\Infrastructure\Identifier\Uuid7Generator;
use Metered\Shared\Infrastructure\Inbox\DatabaseInboxGuard;
use Metered\Shared\Infrastructure\Inbox\IntegrationEventDispatcher;
use Metered\Shared\Infrastructure\Metrics\GaugeObserver;
use Metered\Shared\Infrastructure\Metrics\MeterProviderFactory;
use Metered\Shared\Infrastructure\Metrics\OpenTelemetryMetrics;
use Metered\Shared\Infrastructure\Outbox\DatabaseOutboxWriter;
use Metered\Shared\Infrastructure\Outbox\OutboxGauges;
use Metered\Shared\Infrastructure\Outbox\OutboxRelay;
use Metered\Shared\Infrastructure\Outbox\QueueOutboxPublisher;
use Metered\Shared\Infrastructure\Queue\QueueGauges;
use Metered\Shared\Infrastructure\Tracing\QueueTracing;
use Metered\Shared\Infrastructure\Tracing\TelemetryFlush;
use Metered\Shared\Infrastructure\Tracing\TracerProviderFactory;
use Metered\Shared\Infrastructure\Tracing\Tracing;
use Metered\Shared\Presentation\Console\ObserveMetricsCommand;
use Metered\Shared\Presentation\Console\RelayOutboxCommand;
use Metered\Shared\Presentation\Console\VerifyAuditChainCommand;
use Metered\Shared\Presentation\Http\IdempotencyScope;
use Metered\Shared\Presentation\Http\LivenessController;
use Metered\Shared\Presentation\Http\ReadinessController;
use Metered\Shared\Presentation\Http\RequestAttributeScope;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Wires the shared kernel's ports to their production adapters.
 *
 * The module owns its own wiring rather than leaving it in app/Providers, so
 * everything a module needs in order to work travels with the module.
 */
final class SharedServiceProvider extends ServiceProvider
{
    /**
     * Modules add their integration event handlers to this tag; the dispatcher
     * picks up whatever is tagged, without the kernel knowing who they are.
     */
    public const string HANDLER_TAG = 'metered.integration_event_handlers';

    public function register(): void
    {
        // Where a demo runs, time can be moved forward (sim:time-travel);
        // anywhere else it is the operating system's, and nothing can move it.
        $this->app->singleton(
            ClockInterface::class,
            static fn(Application $app): ClockInterface => $app->environment('local', 'demo')
                ? new TravellingClock(new SystemClock(), $app->make(ClockOffset::class), static fn(): int => (int) hrtime(true))
                : new SystemClock(),
        );
        $this->app->singleton(IdentifierGenerator::class, Uuid7Generator::class);

        $this->app->singleton(OutboxWriter::class, DatabaseOutboxWriter::class);
        $this->app->singleton(OutboxPublisher::class, QueueOutboxPublisher::class);
        $this->app->singleton(InboxGuard::class, DatabaseInboxGuard::class);
        $this->app->singleton(IdempotencyStore::class, DatabaseIdempotencyStore::class);
        $this->app->singleton(IdempotencyScope::class, RequestAttributeScope::class);
        $this->app->singleton(AuditLogger::class, DatabaseAuditLogger::class);
        $this->app->singleton(Transactions::class, DatabaseTransactions::class);
        $this->app->singleton(ChainVerifier::class, DatabaseChainVerifier::class);

        $this->app->singleton(
            TextMapPropagatorInterface::class,
            static fn(): TextMapPropagatorInterface => TraceContextPropagator::getInstance(),
        );

        $this->app->singleton(
            TracerProviderInterface::class,
            static fn(Application $app): TracerProviderInterface => new TracerProviderFactory(
                self::configBool($app, 'metered.tracing.enabled', false),
                self::configString($app, 'metered.tracing.service_name', 'metered'),
                self::configString($app, 'metered.tracing.endpoint', 'http://otel-collector:4318'),
                self::configString($app, 'app.env', 'production'),
            )->make(),
        );

        $this->app->singleton(Tracing::class);
        $this->app->singleton(QueueTracing::class);
        $this->app->singleton(
            TelemetryFlush::class,
            static fn(Application $app): TelemetryFlush => new TelemetryFlush(
                $app->make(TracerProviderInterface::class),
                $app->make(MeterProviderInterface::class),
                static fn(): int => (int) hrtime(true),
            ),
        );

        $this->app->singleton(
            MeterProviderInterface::class,
            static fn(Application $app): MeterProviderInterface => new MeterProviderFactory(
                self::configBool($app, 'metered.tracing.enabled', false),
                self::configString($app, 'metered.tracing.service_name', 'metered'),
                self::configString($app, 'metered.tracing.endpoint', 'http://otel-collector:4318'),
                self::configString($app, 'app.env', 'production'),
            )->make(),
        );
        $this->app->singleton(Metrics::class, OpenTelemetryMetrics::class);

        $this->app->bind(
            OutboxGauges::class,
            static fn(Application $app): OutboxGauges => new OutboxGauges(
                $app->make(DatabaseManager::class),
                $app->make(ClockInterface::class),
                self::configString($app, 'metered.outbox.connection', 'pgsql_direct'),
                self::configInt($app, 'metered.outbox.max_attempts', 10),
            ),
        );
        $this->app->bind(
            QueueGauges::class,
            static fn(Application $app): QueueGauges => new QueueGauges(
                $app->make(QueueFactory::class),
                self::configStringList($app, 'metered.metrics.queues'),
            ),
        );
        $this->app->tag([OutboxGauges::class, QueueGauges::class], GaugeSource::TAG);

        $this->app->singleton(
            GaugeObserver::class,
            static function (Application $app): GaugeObserver {
                $sources = [];

                foreach ($app->tagged(GaugeSource::TAG) as $source) {
                    if ($source instanceof GaugeSource) {
                        $sources[] = $source;
                    }
                }

                return new GaugeObserver($sources, $app->make(MeterProviderInterface::class), $app->make(LoggerInterface::class));
            },
        );

        $this->app->singleton(
            IntegrationEventDispatcher::class,
            static fn(Application $app): IntegrationEventDispatcher => new IntegrationEventDispatcher(
                self::taggedHandlers($app),
                $app->make(InboxGuard::class),
            ),
        );

        $this->app->singleton(
            OutboxRelay::class,
            static fn(Application $app): OutboxRelay => new OutboxRelay(
                $app->make(DatabaseManager::class),
                $app->make(OutboxPublisher::class),
                $app->make(ClockInterface::class),
                $app->make(LoggerInterface::class),
                self::configString($app, 'metered.outbox.connection', 'pgsql_direct'),
                self::configInt($app, 'metered.outbox.max_attempts', 10),
                $app->make(Tracing::class),
            ),
        );

        // What every instance needs; modules tag what they need on top.
        $this->app->tag([DatabaseCheck::class, RedisCheck::class], ReadinessCheck::TAG);

        $this->app->singleton(
            Readiness::class,
            static fn(Application $app): Readiness => new Readiness(
                self::taggedReadinessChecks($app),
                $app->make(LoggerInterface::class),
            ),
        );
    }

    public function boot(): void
    {
        // Built here, on the application Octane clones for every request, so
        // a worker keeps one of each for its life. Resolved first inside a
        // request, they would belong to that request's clone: a new provider
        // per request, exporting one span at a time and restarting every
        // counter from zero.
        foreach ([TracerProviderInterface::class, MeterProviderInterface::class, Tracing::class, Metrics::class, TelemetryFlush::class] as $telemetry) {
            $this->app->make($telemetry);
        }

        QueueTracing::register($this->app->make('events'), fn(): QueueTracing => $this->app->make(QueueTracing::class));

        // The idle points of the long-running processes the framework runs:
        // a queue worker's every pass, and an Octane request once answered.
        // The daemons of this codebase call it from their own loops.
        $flush = function (): void {
            $this->app->make(TelemetryFlush::class)->flushIfDue();
        };
        $this->app->make('events')->listen(Looping::class, $flush);
        $this->app->make('events')->listen(RequestTerminated::class, $flush);

        // No middleware group: a probe carries no session, no API key and no
        // trace, and it must not be rate limited into looking unhealthy.
        Route::get('health/live', LivenessController::class)->name('health.live');
        Route::get('health/ready', ReadinessController::class)->name('health.ready');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ObserveMetricsCommand::class,
                RelayOutboxCommand::class,
                VerifyAuditChainCommand::class,
            ]);
        }
    }

    /**
     * A list, not a generator. The dispatcher is a singleton that a worker
     * keeps for its whole life, and a generator can be walked once: the
     * second event a worker handled would find no handlers left to run.
     *
     * @return list<IntegrationEventHandler>
     */
    private static function taggedHandlers(Application $app): array
    {
        $handlers = [];

        foreach ($app->tagged(self::HANDLER_TAG) as $handler) {
            if ($handler instanceof IntegrationEventHandler) {
                $handlers[] = $handler;
            }
        }

        return $handlers;
    }

    /**
     * @return list<ReadinessCheck>
     */
    private static function taggedReadinessChecks(Application $app): array
    {
        $checks = [];

        foreach ($app->tagged(ReadinessCheck::TAG) as $check) {
            if ($check instanceof ReadinessCheck) {
                $checks[] = $check;
            }
        }

        return $checks;
    }

    private static function configString(Application $app, string $key, string $default): string
    {
        $value = $app->make('config')->get($key);

        return is_string($value) ? $value : $default;
    }

    /**
     * @return list<string>
     */
    private static function configStringList(Application $app, string $key): array
    {
        $value = $app->make('config')->get($key);

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    private static function configBool(Application $app, string $key, bool $default): bool
    {
        $value = $app->make('config')->get($key);

        return is_bool($value) ? $value : $default;
    }

    private static function configInt(Application $app, string $key, int $default): int
    {
        $value = $app->make('config')->get($key);

        return is_int($value) ? $value : $default;
    }
}
