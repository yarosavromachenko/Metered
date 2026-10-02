<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Stream;

/**
 * One pipelined call per request (ADR-0003).
 */
interface EventStream
{
    /**
     * @throws StreamFull when the stream has no memory left for the batch
     */
    public function append(Batch $batch): void;
}
