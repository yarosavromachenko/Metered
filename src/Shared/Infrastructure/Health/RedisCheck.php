<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Health;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Metered\Shared\Application\Health\CheckResult;
use Metered\Shared\Application\Health\ReadinessCheck;

/**
 * Redis answers a PING. Queues, cache, rate limits and the ingestion stream
 * all live there; without it the application can serve nothing but errors.
 */
final readonly class RedisCheck implements ReadinessCheck
{
    public function __construct(
        private RedisFactory $redis,
        private string $connection = 'default',
    ) {}

    public function name(): string
    {
        return 'redis';
    }

    public function check(): CheckResult
    {
        $this->redis->connection($this->connection)->command('ping');

        return CheckResult::pass();
    }
}
