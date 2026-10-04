<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Health;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Metered\Shared\Application\Health\CheckResult;
use Metered\Shared\Application\Health\ReadinessCheck;

/**
 * The main Redis. The ingestion Redis is covered by the backlog check.
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
