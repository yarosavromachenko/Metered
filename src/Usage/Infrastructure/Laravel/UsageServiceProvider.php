<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Metered\Usage\Infrastructure\Persistence\PartitionManager;
use Metered\Usage\Presentation\Console\EnsurePartitionsCommand;

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
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([EnsurePartitionsCommand::class]);
        }
    }

    private static function connection(Application $app): string
    {
        $configured = $app->make('config')->get('metered.usage.connection');

        return is_string($configured) && $configured !== '' ? $configured : 'pgsql_direct';
    }
}
