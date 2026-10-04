<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Command;

use RuntimeException;

/**
 * Backlog over the limit or stream out of memory. Carries the Retry-After value.
 */
final class IngestionOverloaded extends RuntimeException
{
    private function __construct(
        public readonly int $retryAfterSeconds,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function atDepth(int $pending, int $threshold, int $retryAfterSeconds): self
    {
        return new self($retryAfterSeconds, sprintf(
            'Ingestion is shedding load: %d messages are waiting and the limit is %d.',
            $pending,
            $threshold,
        ));
    }

    public static function outOfMemory(int $retryAfterSeconds): self
    {
        return new self($retryAfterSeconds, 'Ingestion is shedding load: the usage stream has no memory left.');
    }
}
