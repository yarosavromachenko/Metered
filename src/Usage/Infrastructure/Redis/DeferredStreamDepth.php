<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Redis;

use Closure;
use Metered\Usage\Application\Stream\StreamDepth;

/**
 * A stream depth that builds the real one on first use.
 *
 * Building {@see RedisEventStream} opens its Redis connection. The readiness
 * check is built when the probe is first answered, outside the guard that
 * turns a failed check into "unreachable", so with Redis down a fresh worker
 * would answer the probe with a 500 instead of a 503. Deferred, the
 * connection is opened inside the check, where its failure is reported.
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
