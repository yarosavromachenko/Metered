<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Ingestion;

/**
 * Each event is exactly one of: counted, duplicate, rejected, failed.
 */
final readonly class IngestionOutcome
{
    public function __construct(
        public int $counted = 0,
        public int $duplicates = 0,
        public int $rejected = 0,
    ) {}

    public function plus(self $other): self
    {
        return new self(
            $this->counted + $other->counted,
            $this->duplicates + $other->duplicates,
            $this->rejected + $other->rejected,
        );
    }

    public function total(): int
    {
        return $this->counted + $this->duplicates + $this->rejected;
    }
}
