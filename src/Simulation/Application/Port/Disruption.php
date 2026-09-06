<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Port;

/**
 * The failures chaos scenarios cause. The simulation does not reach the
 * stack's own containers, so it brings up daemons of its own — a consumer,
 * a relay — and kills those, which is the same failure from the point of view
 * of everything else: a process that held work and vanished.
 */
interface Disruption
{
    /**
     * Starts an artisan daemon in a process of its own.
     *
     * @param  list<string>  $arguments
     * @return string a handle for kill()
     */
    public function start(string $command, array $arguments = []): string;

    /**
     * SIGKILL: no shutdown, no acknowledgement, nothing flushed.
     */
    public function kill(string $handle): void;

    /**
     * Messages delivered to this consumer of the usage stream and never
     * acknowledged.
     */
    public function pendingOf(string $consumer): int;

    /**
     * Every Redis client is held for this long — to the platform, Redis is
     * gone and then back.
     */
    public function stallRedis(int $milliseconds): void;
}
