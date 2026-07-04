<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Ingestion;

/**
 * What became of a batch — the numbers the daemon logs and the metrics count.
 *
 * Four outcomes, and every event is exactly one of them: counted, already
 * known, refused, or still in the batch that failed. A batch whose numbers do
 * not add up to what went in is a bug this shape makes visible.
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
