<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Stream;

/**
 * How much work is waiting, so that ingestion can refuse to take more.
 *
 * Separate from {@see EventStream} because the two are asked by different
 * people for different reasons: the endpoint writes, the backpressure check
 * and the panel's lag widget read. A single interface would force every
 * implementation of either to answer both.
 */
interface StreamDepth
{
    /**
     * Messages accepted but not yet written: waiting for the consumer, or
     * held by it and not yet acknowledged. Not the size of the stream, which
     * keeps entries after they have been dealt with.
     *
     * Approximate by nature — it is a number about a moving queue — and used
     * only for decisions that tolerate being slightly stale.
     */
    public function pending(): int;
}
