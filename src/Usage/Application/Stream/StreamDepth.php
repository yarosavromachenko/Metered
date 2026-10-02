<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Stream;

/**
 * Read by the backpressure check and the panel; {@see EventStream} is the
 * write side.
 */
interface StreamDepth
{
    /**
     * Undelivered plus unacknowledged messages (not the stream length).
     * Approximate.
     */
    public function pending(): int;
}
