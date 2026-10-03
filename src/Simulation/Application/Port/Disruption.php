<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Port;

/**
 * Starts its own daemons (consumer, relay) and kills them; the stack's
 * containers are not touched.
 */
interface Disruption
{
    /**
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
     * CLIENT PAUSE for this long.
     */
    public function stallRedis(int $milliseconds): void;
}
