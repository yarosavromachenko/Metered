<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Metered\Shared\Application\Metrics\Gauge;
use Metered\Shared\Application\Metrics\GaugeReading;
use Metered\Shared\Application\Metrics\GaugeSource;
use Metered\Shared\Application\Outbox\OutboxWriter;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Outbox\OutboxMessage;
use Metered\Shared\Infrastructure\Metrics\GaugeObserver;
use Metered\Shared\Infrastructure\Outbox\OutboxGauges;
use Metered\Shared\Infrastructure\Queue\QueueGauges;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Tests\Support\InMemoryMetrics;

/**
 * The gauges: the shared kernel's own sources against the real database and
 * queue, and the observer that reads every source a module tags.
 */
function waitingOutboxMessage(string $occurredAt): void
{
    $ids = app(IdentifierGenerator::class);

    app(OutboxWriter::class)->append(new OutboxMessage(
        id: $ids->generate(),
        aggregateType: 'invoice',
        aggregateId: $ids->generate(),
        type: 'test.waiting',
        payload: [],
        headers: [],
        occurredAt: new DateTimeImmutable($occurredAt),
    ));
}

function outboxGauges(MockClock $clock, int $maxAttempts = 10): OutboxGauges
{
    return new OutboxGauges(app('db'), $clock, testConnection(), $maxAttempts);
}

it('reads the outbox lag as the age of the oldest message still to publish', function (): void {
    waitingOutboxMessage('2026-09-29 12:00:00');
    waitingOutboxMessage('2026-09-29 12:04:00');

    $reading = outboxGauges(new MockClock('2026-09-29 12:05:30', 'UTC'))->read()[0];

    expect($reading->gauge->name)->toBe('outbox.unpublished.age')
        ->and($reading->value)->toBe(330);
});

it('reads no lag once nothing is waiting, and leaves out what ran out of attempts', function (): void {
    waitingOutboxMessage('2026-09-29 12:00:00');
    // The relay gave up on it: counted, it would be a lag that never goes down.
    DB::table('outbox_messages')->update(['attempts' => 10]);

    expect(outboxGauges(new MockClock('2026-09-29 13:00:00', 'UTC'))->read()[0]->value)->toBe(0);
});

it('reads each queue\'s depth', function (): void {
    config(['queue.default' => 'database']);
    Queue::connection('database')->pushRaw('{"job":"x"}', 'billing');
    Queue::connection('database')->pushRaw('{"job":"x"}', 'billing');

    $readings = new QueueGauges(app('queue'), ['billing', 'webhooks'])->read();

    expect(array_map(static fn(GaugeReading $reading): array => [$reading->labels['queue'] ?? '', $reading->value], $readings))
        ->toBe([['billing', 2], ['webhooks', 0]]);
});

it('reports what every source read, and a failing source does not hold the others back', function (): void {
    $metrics = new InMemoryMetrics();
    $depth = new Gauge('usage.stream.pending', '{message}', 'Waiting');
    $healthy = new readonly class ($depth) implements GaugeSource {
        public function __construct(private Gauge $gauge) {}

        public function read(): array
        {
            return [new GaugeReading($this->gauge, 42)];
        }
    };
    $broken = new readonly class implements GaugeSource {
        public function read(): array
        {
            throw new RuntimeException('Redis went away');
        }
    };

    $observer = new GaugeObserver([$broken, $healthy], $metrics->provider, new NullLogger());

    expect($observer->observe())->toBe(1)
        ->and($metrics->gauge('usage.stream.pending'))->toBe(42);
});

it('reports only the last pass, so a value that went away stops being reported', function (): void {
    $metrics = new InMemoryMetrics();
    $open = new Gauge('webhook.breaker.open', '{breaker}', 'Open');
    $readings = new ArrayObject([new GaugeReading($open, 1, ['endpoint' => 'e-1'])]);
    $source = new readonly class ($readings) implements GaugeSource {
        /** @param ArrayObject<int, GaugeReading> $readings */
        public function __construct(private ArrayObject $readings) {}

        public function read(): array
        {
            return array_values($this->readings->getArrayCopy());
        }
    };
    $observer = new GaugeObserver([$source], $metrics->provider, new NullLogger());

    $observer->observe();
    expect($metrics->gauge('webhook.breaker.open', ['endpoint' => 'e-1']))->toBe(1);

    $readings->exchangeArray([]);
    $observer->observe();
    expect($metrics->gauge('webhook.breaker.open', ['endpoint' => 'e-1']))->toBeNull();
});

it('observes once and exits when asked to', function (): void {
    InMemoryMetrics::install();
    app()->instance(ClockInterface::class, new MockClock('2026-09-29 12:00:00', 'UTC'));

    expect(Artisan::call('metrics:observe', ['--once' => true]))->toBe(0)
        ->and(Artisan::output())->toMatch('/Observed \d+ reading\(s\)\./');
});
