<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Ingestion;

/**
 * `inserted` excludes rows the unique index refused as duplicates (ADR-0004).
 */
final readonly class WriteOutcome
{
    public function __construct(
        public int $inserted,
        public int $duplicates,
        public int $bucketsTouched,
    ) {}
}
