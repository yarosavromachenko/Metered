<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Redis;

use Closure;
use Metered\Usage\Application\Stream\StreamDepth;

/**
 * Builds {@see RedisEventStream} (which connects) on first use, so with Redis
 * down the readiness check reports 503 instead of failing with 500.
 */
final class DeferredStreamDepth implements StreamDepth
{
    private ?StreamDepth $depth = null;

    /**
     * @param  Closure(): StreamDepth  $resolve
     */
    public function __construct(
        private readonly Closure $resolve,
    ) {}

    public function pending(): int
    {
        $this->depth ??= ($this->resolve)();

        return $this->depth->pending();
    }
}
