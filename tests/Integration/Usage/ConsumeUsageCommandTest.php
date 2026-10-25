<?php

declare(strict_types=1);

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Sleep;
use Metered\Usage\Application\Ingestion\RejectionLog;
use Metered\Usage\Infrastructure\Redis\StreamConsumer;
use Tests\Support\TenantFactory;
use Tests\Support\UsageStream;

/**
 * The daemon around the consumer: how long it waits after a pass that failed.
 * The failure is real — the consumer is pointed at a database nobody listens on.
 */
beforeEach(function (): void {
    app(RedisFactory::class)->connection('usage')->command('del', [UsageStream::key()]);
    Sleep::fake();
    config(['metered.usage.consumer.block_milliseconds' => 1]);
});

function pointConsumerAtNoDatabase(): void
{
    $connection = config('database.connections.' . testConnection());
    config([
        'database.connections.unreachable' => [...(is_array($connection) ? $connection : []), 'host' => '127.0.0.1', 'port' => 1],
        'metered.usage.connection' => 'unreachable',
    ]);
    app()->forgetInstance(StreamConsumer::class);
}

it('waits longer after each failed pass, up to the limit', function (): void {
    pointConsumerAtNoDatabase();
    config([
        'metered.usage.consumer.backoff_initial_milliseconds' => 500,
        'metered.usage.consumer.backoff_max_milliseconds' => 1500,
    ]);

    $exit = Artisan::call('usage:consume', ['--max-batches' => '5', '--consumer' => 'backoff']);

    expect($exit)->toBe(1);
    Sleep::assertSequence([
        Sleep::for(500)->milliseconds(),
        Sleep::for(1000)->milliseconds(),
        Sleep::for(1500)->milliseconds(),
        Sleep::for(1500)->milliseconds(),
    ]);
});

it('does not wait after a pass that succeeds', function (): void {
    $exit = Artisan::call('usage:consume', ['--max-batches' => '3', '--consumer' => 'healthy']);

    expect($exit)->toBe(0);
    Sleep::assertNeverSlept();
});

it('exits with a failure when the single pass of --once fails', function (): void {
    pointConsumerAtNoDatabase();

    expect(Artisan::call('usage:consume', ['--once' => true, '--consumer' => 'once']))->toBe(1);
    Sleep::assertNeverSlept();
});

it('retries at once when a write failed with the database reachable', function (): void {
    $tenant = TenantFactory::tenant()->tenant();
    app(RedisFactory::class)->connection('usage')->command('xadd', [UsageStream::key(), '*', [
        'v' => '99',
        'organization_id' => $tenant->organizationId->value,
        'project_id' => $tenant->projectId->value,
    ]]);
    app()->bind(RejectionLog::class, static fn(): RejectionLog => new class implements RejectionLog {
        public function record(array $rejections): void
        {
            throw new RuntimeException('Recording the rejection failed.');
        }
    });
    app()->forgetInstance(StreamConsumer::class);

    expect(Artisan::call('usage:consume', ['--max-batches' => '2', '--consumer' => 'retry']))->toBe(0)
        ->and(Artisan::output())->toContain('Batch failed: Recording the rejection failed.');
    Sleep::assertNeverSlept();
});
