<?php

declare(strict_types=1);

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Facade;
use Metered\Shared\Infrastructure\Health\DatabaseCheck;

use function Pest\Laravel\getJson;

use Tests\Support\UsageStream;

/**
 * The probes against the real PostgreSQL and Redis. An unreachable
 * dependency is a connection pointed at a closed port: the same refusal a
 * stopped container gives, without stopping anything the rest of the suite
 * needs. Each such test first proves the connection really is refused, so
 * that a misconfigured test cannot pass on some other exception.
 */
beforeEach(function (): void {
    app(RedisFactory::class)->connection('usage')->command('del', [UsageStream::key()]);
});

it('answers liveness without asking anything else', function (): void {
    refuseRedis('default');
    refuseRedis('usage');

    getJson('/health/live')->assertOk()->assertExactJson(['status' => 'live']);
});

it('is ready when the database, Redis and the backlog all pass', function (): void {
    getJson('/health/ready')
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('checks.database.status', 'pass')
        ->assertJsonPath('checks.redis.status', 'pass')
        ->assertJsonPath('checks.usage_backlog.status', 'pass')
        ->assertJsonPath('checks.usage_backlog.detail', '0 pending of 500000');
});

it('is not ready when PostgreSQL cannot be reached', function (): void {
    config(['database.connections.refused' => ['host' => '127.0.0.1', 'port' => 1] + config()->array('database.connections.' . testConnection())]);
    $database = app(DatabaseManager::class);

    expect(fn() => $database->connection('refused')->select('select 1'))->toThrow(QueryException::class, 'Connection refused');

    app()->instance(DatabaseCheck::class, new DatabaseCheck($database, 'refused'));

    getJson('/health/ready')
        ->assertStatus(503)
        ->assertJsonPath('status', 'not_ready')
        ->assertJsonPath('checks.database', ['status' => 'fail', 'detail' => 'unreachable'])
        ->assertJsonPath('checks.redis.status', 'pass');
});

it('is not ready when Redis cannot be reached', function (): void {
    refuseRedis('default');

    getJson('/health/ready')
        ->assertStatus(503)
        ->assertJsonPath('checks.redis', ['status' => 'fail', 'detail' => 'unreachable'])
        ->assertJsonPath('checks.database.status', 'pass');
});

it('reports an unreachable stream as a failed check before anything has connected to it', function (): void {
    // A fresh worker has not built the stream yet, and building it connects.
    // That must happen inside the check, or the probe answers 500.
    refuseRedis('usage');

    getJson('/health/ready')
        ->assertStatus(503)
        ->assertJsonPath('checks.usage_backlog', ['status' => 'fail', 'detail' => 'unreachable'])
        ->assertJsonPath('checks.redis.status', 'pass');
});

it('is not ready once the backlog reaches the backpressure threshold', function (): void {
    config(['metered.usage.stream.backpressure_threshold' => 2]);
    $stream = app(RedisFactory::class)->connection('usage');
    $stream->command('xadd', [UsageStream::key(), '*', ['v' => '1']]);

    getJson('/health/ready')->assertOk()->assertJsonPath('checks.usage_backlog.detail', '1 pending of 2');

    $stream->command('xadd', [UsageStream::key(), '*', ['v' => '1']]);

    getJson('/health/ready')
        ->assertStatus(503)
        ->assertJsonPath('checks.usage_backlog', ['status' => 'fail', 'detail' => '2 pending of 2']);
});

it('needs no session, API key or tenant', function (): void {
    getJson('/health/ready')->assertOk()->assertCookieMissing(config()->string('session.cookie'));
});

/**
 * Points a Redis connection at a closed port and proves it is refused. The
 * manager reads its configuration once, so it is rebuilt.
 */
function refuseRedis(string $connection): void
{
    config(['database.redis.' . $connection => ['host' => '127.0.0.1', 'port' => 1] + config()->array('database.redis.' . $connection)]);
    app()->forgetInstance('redis');
    Facade::clearResolvedInstance('redis');

    expect(fn() => app(RedisFactory::class)->connection($connection)->command('ping'))
        ->toThrow(RedisException::class, 'Connection refused');
}
