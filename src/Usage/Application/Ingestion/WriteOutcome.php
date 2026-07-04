<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Ingestion;

/**
 * What one write actually did.
 *
 * `inserted` is not the number of events handed over: rows the database
 * refused as exact duplicates contribute nothing, and that difference is the
 * exactly-once effect being visible rather than assumed (ADR-0004).
 */
final readonly class WriteOutcome
{
    public function __construct(
        public int $inserted,
        public int $duplicates,
        public int $bucketsTouched,
    ) {}
}
