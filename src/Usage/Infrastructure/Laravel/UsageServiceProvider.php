<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Metered\Billing\Application\Contract\CustomerDirectory;
use Metered\Billing\Application\Contract\MeterCatalog;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Usage\Application\Command\IngestEventsHandler;
use Metered\Usage\Application\Contract\UsageTotals;
use Metered\Usage\Application\Ingestion\BatchProcessor;
use Metered\Usage\Application\Ingestion\Deduplicator;
use Metered\Usage\Application\Ingestion\EventWriter;
use Metered\Usage\Application\Ingestion\RejectionLog;
use Metered\Usage\Application\Stream\EventStream;
use Metered\Usage\Application\Stream\StreamDepth;
use Metered\Usage\Domain\AcceptanceWindow;
use Metered\Usage\Infrastructure\Persistence\DatabaseEventWriter;
use Metered\Usage\Infrastructure\Persistence\DatabaseRejectionLog;
use Metered\Usage\Infrastructure\Persistence\DatabaseUsageTotals;
use Metered\Usage\Infrastructure\Persistence\PartitionManager;
use Metered\Usage\Infrastructure\Persistence\UsageReconciler;
use Metered\Usage\Infrastructure\Persistence\UsageSummaryReader;
use Metered\Usage\Infrastructure\Redis\RedisDeduplicator;
use Metered\Usage\Infrastructure\Redis\RedisEventStream;
use Metered\Usage\Infrastructure\Redis\StreamConsumer;
use Metered\Usage\Presentation\Console\ConsumeUsageCommand;
use Metered\Usage\Presentation\Console\EnsurePartitionsCommand;
use Metered\Usage\Presentation\Console\ReconcileUsageCommand;
use Metered\Usage\Presentation\Http\IngestEventsController;
use Metered\Usage\Presentation\Http\ReadCustomerUsageController;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Wires ingestion.
 *
 * Everything here that talks to PostgreSQL does so on the configured usage
 * connection, which is a direct one: the consumer and the partition commands
 * are long-running or DDL-issuing, and PgBouncer's transaction pooling suits
 * neither (ADR-0003).
 */
final class UsageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            PartitionManager::class,
            static fn(Application $app): PartitionManager => new PartitionManager(
                $app->make(DatabaseManager::class),
                self::connection($app),
            ),
        );

        // One object implements both stream ports. They are separate
        // interfaces because writing and measuring are asked for by different
        // callers, not because two implementations were wanted.
        $this->app->singleton(
            RedisEventStream::class,
            static fn(Application $app): RedisEventStream => new RedisEventStream(
                self::redis($app),
                self::configString($app, 'metered.usage.stream.key', 'usage:events'),
                self::configString($app, 'metered.usage.stream.group', 'usage-writers'),
                self::configInt($app, 'metered.usage.stream.max_length', 1_000_000),
            ),
        );
        $this->app->singleton(EventStream::class, RedisEventStream::class);
        $this->app->singleton(StreamDepth::class, RedisEventStream::class);
        $this->app->singleton(UsageTotals::class, DatabaseUsageTotals::class);

        $this->app->singleton(
            IngestEventsHandler::class,
            static fn(Application $app): IngestEventsHandler => new IngestEventsHandler(
                $app->make(EventStream::class),
                $app->make(StreamDepth::class),
                $app->make(IdentifierGenerator::class),
                $app->make(ClockInterface::class),
                self::configInt($app, 'metered.usage.stream.backpressure_threshold', 500_000),
                self::configInt($app, 'metered.usage.stream.retry_after_seconds', 5),
            ),
        );

        $this->app->singleton(
            UsageReconciler::class,
            static fn(Application $app): UsageReconciler => new UsageReconciler(
                $app->make(DatabaseManager::class),
                self::connection($app),
            ),
        );

        $this->app->singleton(
            UsageSummaryReader::class,
            static fn(Application $app): UsageSummaryReader => new UsageSummaryReader(
                $app->make(DatabaseManager::class),
                self::connection($app),
            ),
        );

        $this->app->singleton(
            AcceptanceWindow::class,
            static fn(Application $app): AcceptanceWindow => AcceptanceWindow::of(
                self::configInt($app, 'metered.usage.acceptance.max_age_seconds', 604_800),
                self::configInt($app, 'metered.usage.acceptance.max_drift_seconds', 300),
            ),
        );

        $this->app->singleton(
            EventWriter::class,
            static fn(Application $app): EventWriter => new DatabaseEventWriter(
                $app->make(DatabaseManager::class),
                self::connection($app),
                $app->make(ClockInterface::class),
            ),
        );

        $this->app->singleton(
            RejectionLog::class,
            static fn(Application $app): RejectionLog => new DatabaseRejectionLog(
                $app->make(DatabaseManager::class),
                self::connection($app),
            ),
        );

        $this->app->singleton(
            Deduplicator::class,
            static fn(Application $app): Deduplicator => new RedisDeduplicator(
                self::redis($app),
                self::configInt($app, 'metered.usage.deduplication.ttl_seconds', 604_800),
            ),
        );

        $this->app->singleton(
            BatchProcessor::class,
            static fn(Application $app): BatchProcessor => new BatchProcessor(
                $app->make(MeterCatalog::class),
                $app->make(CustomerDirectory::class),
                $app->make(Deduplicator::class),
                $app->make(EventWriter::class),
                $app->make(RejectionLog::class),
                $app->make(IdentifierGenerator::class),
                $app->make(ClockInterface::class),
                $app->make(AcceptanceWindow::class),
            ),
        );

        $this->app->singleton(
            StreamConsumer::class,
            static fn(Application $app): StreamConsumer => new StreamConsumer(
                self::redis($app),
                $app->make(BatchProcessor::class),
                $app->make(RejectionLog::class),
                $app->make(IdentifierGenerator::class),
                $app->make(ClockInterface::class),
                self::configString($app, 'metered.usage.stream.key', 'usage:events'),
                self::configString($app, 'metered.usage.stream.dead_letter_key', 'usage:events:dead'),
                self::configString($app, 'metered.usage.stream.group', 'usage-writers'),
                self::configInt($app, 'metered.usage.consumer.batch_size', 500),
                self::configInt($app, 'metered.usage.consumer.block_milliseconds', 2000),
                self::configInt($app, 'metered.usage.consumer.reclaim_idle_milliseconds', 60_000),
                self::configInt($app, 'metered.usage.consumer.max_deliveries', 5),
            ),
        );

        $this->app->singleton(
            IngestEventsController::class,
            static fn(Application $app): IngestEventsController => new IngestEventsController(
                $app->make(IngestEventsHandler::class),
                self::configInt($app, 'metered.usage.batch_limit', 100),
            ),
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                EnsurePartitionsCommand::class,
                ConsumeUsageCommand::class,
                ReconcileUsageCommand::class,
            ]);
        }

        // The module carries its own routes, as it carries its own screens: a
        // module that is deleted takes its endpoints with it, and
        // routes/api.php never learns what any of them were.
        Route::middleware(['api', 'api-key:usage:write', 'throttle-api-key'])
            ->prefix('api/v1')
            ->group(static function (): void {
                Route::post('usage/events', IngestEventsController::class)->name('usage.events.ingest');
            });

        // Reading somebody's usage is an admin key's business, not an
        // ingestion key's: the key in a client's product should be able to
        // report usage and nothing else, so that leaking it leaks nothing
        // about their customers.
        Route::middleware(['api', 'api-key:admin', 'throttle-api-key'])
            ->prefix('api/v1')
            ->group(static function (): void {
                Route::get('customers/{reference}/usage', ReadCustomerUsageController::class)
                    ->name('usage.customer.read');
            });
    }

    private static function redis(Application $app): PhpRedisConnection
    {
        $connection = $app->make(RedisFactory::class)->connection(
            self::configString($app, 'metered.usage.stream.connection', 'default'),
        );

        // phpredis, specifically: streams are used through the client's own
        // pipeline, and predis would need a different adapter rather than a
        // different configuration value (ADR-0003).
        return $connection instanceof PhpRedisConnection
            ? $connection
            : throw new RuntimeException('Usage ingestion needs the phpredis client.');
    }

    private static function configInt(Application $app, string $key, int $default): int
    {
        $value = $app->make('config')->get($key);

        return is_int($value) ? $value : $default;
    }

    private static function configString(Application $app, string $key, string $default): string
    {
        $value = $app->make('config')->get($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private static function connection(Application $app): string
    {
        $configured = $app->make('config')->get('metered.usage.connection');

        return is_string($configured) && $configured !== '' ? $configured : 'pgsql_direct';
    }
}
