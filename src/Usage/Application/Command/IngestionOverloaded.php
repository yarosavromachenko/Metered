<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Command;

use RuntimeException;

/**
 * The stream is deeper than the consumer is draining it, or has no memory
 * left, so ingestion stops accepting rather than accepting work it is
 * visibly failing to do.
 *
 * Carries how long to wait, because a client that is told to back off without
 * being told for how long will retry immediately.
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
