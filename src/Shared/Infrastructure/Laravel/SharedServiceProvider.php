<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Metered\Shared\Application\Inbox\InboxGuard;
use Metered\Shared\Application\Inbox\IntegrationEventHandler;
use Metered\Shared\Application\Outbox\OutboxPublisher;
use Metered\Shared\Application\Outbox\OutboxWriter;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Infrastructure\Clock\SystemClock;
use Metered\Shared\Infrastructure\Identifier\Uuid7Generator;
use Metered\Shared\Infrastructure\Inbox\DatabaseInboxGuard;
use Metered\Shared\Infrastructure\Inbox\IntegrationEventDispatcher;
use Metered\Shared\Infrastructure\Outbox\DatabaseOutboxWriter;
use Metered\Shared\Infrastructure\Outbox\OutboxRelay;
use Metered\Shared\Infrastructure\Outbox\QueueOutboxPublisher;
use Metered\Shared\Presentation\Console\RelayOutboxCommand;
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
        $this->app->singleton(ClockInterface::class, SystemClock::class);
        $this->app->singleton(IdentifierGenerator::class, Uuid7Generator::class);

        $this->app->singleton(OutboxWriter::class, DatabaseOutboxWriter::class);
        $this->app->singleton(OutboxPublisher::class, QueueOutboxPublisher::class);
        $this->app->singleton(InboxGuard::class, DatabaseInboxGuard::class);

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
            ),
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RelayOutboxCommand::class]);
        }
    }

    /**
     * @return iterable<IntegrationEventHandler>
     */
    private static function taggedHandlers(Application $app): iterable
    {
        foreach ($app->tagged(self::HANDLER_TAG) as $handler) {
            if ($handler instanceof IntegrationEventHandler) {
                yield $handler;
            }
        }
    }

    private static function configString(Application $app, string $key, string $default): string
    {
        $value = $app->make('config')->get($key);

        return is_string($value) ? $value : $default;
    }

    private static function configInt(Application $app, string $key, int $default): int
    {
        $value = $app->make('config')->get($key);

        return is_int($value) ? $value : $default;
    }
}
