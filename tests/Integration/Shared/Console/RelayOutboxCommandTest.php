<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Metered\Shared\Application\Outbox\OutboxWriter;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Outbox\OutboxMessage;
use Metered\Shared\Infrastructure\Outbox\OutboxRelay;
use Metered\Shared\Infrastructure\Queue\DeliverIntegrationEvent;
use Psr\Clock\ClockInterface;

function writeMessage(string $type): OutboxMessage
{
    $ids = app(IdentifierGenerator::class);

    $message = new OutboxMessage(
        id: $ids->generate(),
        aggregateType: 'invoice',
        aggregateId: $ids->generate(),
        type: $type,
        payload: ['total' => 1999],
        headers: [],
        occurredAt: app(ClockInterface::class)->now(),
    );

    app(OutboxWriter::class)->append($message);

    return $message;
}

it('publishes a single batch and exits', function (): void {
    Bus::fake();
    $message = writeMessage('invoice.finalized');

    expect(Artisan::call('outbox:relay', ['--once' => true]))->toBe(0);

    Bus::assertDispatched(
        DeliverIntegrationEvent::class,
        static fn(DeliverIntegrationEvent $job): bool => $job->id === $message->id->value,
    );

    expect(DB::table('outbox_messages')->where('id', $message->id->value)->value('published_at'))
        ->not->toBeNull();
});

it('honours the batch size it is given', function (): void {
    Bus::fake();

    foreach (range(1, 4) as $i) {
        writeMessage('event.' . $i);
    }

    expect(Artisan::call('outbox:relay', ['--once' => true, '--batch' => '2']))->toBe(0);

    Bus::assertDispatchedTimes(DeliverIntegrationEvent::class, 2);
    expect(DB::table('outbox_messages')->whereNull('published_at')->count())->toBe(2);
});

it('says nothing and succeeds when there is nothing to publish', function (): void {
    Bus::fake();

    expect(Artisan::call('outbox:relay', ['--once' => true]))->toBe(0);

    Bus::assertNothingDispatched();
});

/**
 * Registers a connection named "relay_target" that differs from the test
 * connection by $overrides, and makes the relay use it.
 *
 * @param array<string, mixed> $overrides
 */
function pointRelayAt(array $overrides): void
{
    $connection = config('database.connections.' . testConnection());

    config([
        'database.connections.relay_target' => [...(is_array($connection) ? $connection : []), ...$overrides],
        'metered.outbox.connection' => 'relay_target',
    ]);
    app()->forgetInstance(OutboxRelay::class);
    DB::purge('relay_target');
}

describe('while PostgreSQL is unreachable', function (): void {
    beforeEach(function (): void {
        Sleep::fake();
        Bus::fake();
    });

    it('waits longer after each failed pass, up to the limit', function (): void {
        pointRelayAt(['host' => '127.0.0.1', 'port' => 1]);
        config([
            'metered.outbox.backoff_initial_milliseconds' => 500,
            'metered.outbox.backoff_max_milliseconds' => 1500,
        ]);

        expect(Artisan::call('outbox:relay', ['--max-batches' => '5']))->toBe(0);

        Sleep::assertSequence([
            Sleep::for(500)->milliseconds(),
            Sleep::for(1000)->milliseconds(),
            Sleep::for(1500)->milliseconds(),
            Sleep::for(1500)->milliseconds(),
        ]);
    });

    it('publishes what was waiting once the database is back', function (): void {
        $message = writeMessage('invoice.finalized');
        pointRelayAt(['host' => '127.0.0.1', 'port' => 1]);

        // The test runs inside a transaction on its own connection, so the
        // database "comes back" as that same PDO, at the same transaction level.
        Sleep::whenFakingSleep(static function (): void {
            $test = DB::connection(testConnection());

            DB::purge('relay_target');
            $recovered = DB::connection('relay_target');
            $recovered->setPdo($test->getPdo());
            new ReflectionProperty(Connection::class, 'transactions')->setValue($recovered, $test->transactionLevel());
        });

        expect(Artisan::call('outbox:relay', ['--max-batches' => '3']))->toBe(0);

        Bus::assertDispatched(
            DeliverIntegrationEvent::class,
            static fn(DeliverIntegrationEvent $job): bool => $job->id === $message->id->value,
        );
        Sleep::assertSleptTimes(1);
    });

    it('fails the single pass of --once instead of waiting', function (): void {
        pointRelayAt(['host' => '127.0.0.1', 'port' => 1]);

        expect(static fn(): int => Artisan::call('outbox:relay', ['--once' => true]))->toThrow(PDOException::class);

        Sleep::assertNeverSlept();
    });
});

it('does not wait after a pass that succeeds', function (): void {
    Sleep::fake();
    Bus::fake();

    expect(Artisan::call('outbox:relay', ['--max-batches' => '3']))->toBe(0);

    Sleep::assertNeverSlept();
});

it('stops on a failure that is not an outage', function (): void {
    Sleep::fake();
    Bus::fake();
    pointRelayAt(['database' => 'no_such_database']);

    expect(static fn(): int => Artisan::call('outbox:relay', ['--max-batches' => '3']))->toThrow(PDOException::class);

    Sleep::assertNeverSlept();
});
