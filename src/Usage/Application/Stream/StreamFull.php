<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Stream;

use RuntimeException;
use Throwable;

/**
 * The stream has no memory left to take a batch. Ingestion answers it the way
 * it answers a backlog — come back later — because it is not the client's
 * fault. Unlike a backlog it does not clear by itself: the consumer's
 * deduplication claims need memory too, so it waits for an operator to raise
 * the limit (ADR-0002, "Capacity").
 *
 * Part of the batch may already be in the stream. The client's retry sends
 * all of it again, and deduplication drops what had landed.
 */
final class StreamFull extends RuntimeException
{
    public static function because(Throwable $refusal): self
    {
        return new self('The usage stream has no memory left: ' . $refusal->getMessage(), 0, $refusal);
    }
}
