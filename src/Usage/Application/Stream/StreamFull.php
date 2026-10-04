<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Stream;

use RuntimeException;
use Throwable;

/**
 * Redis is out of memory. Answered with 503 like a backlog, but needs an
 * operator to raise the limit (ADR-0002, "Capacity"). Part of the batch may
 * have landed; deduplication drops it on retry.
 */
final class StreamFull extends RuntimeException
{
    public static function because(Throwable $refusal): self
    {
        return new self('The usage stream has no memory left: ' . $refusal->getMessage(), 0, $refusal);
    }
}
