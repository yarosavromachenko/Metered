<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Redis;

use Metered\Usage\Application\Ingestion\IngestionOutcome;

final readonly class ConsumeReport
{
    public function __construct(
        public int $read = 0,
        public int $reclaimed = 0,
        public int $deadLettered = 0,
        public IngestionOutcome $outcome = new IngestionOutcome(),
    ) {}

    public function isEmpty(): bool
    {
        return $this->read === 0 && $this->reclaimed === 0 && $this->deadLettered === 0;
    }
}
