<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Stream;

/**
 * Where an accepted batch goes, and the only thing the hot path writes to.
 *
 * One call per request, not one per event: the point of the design is that
 * ingestion costs a pipelined round trip to Redis and nothing else
 * (ADR-0003).
 */
interface EventStream
{
    public function append(Batch $batch): void;
}
