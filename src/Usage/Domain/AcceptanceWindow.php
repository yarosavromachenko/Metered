<?php

declare(strict_types=1);

namespace Metered\Usage\Domain;

use DateTimeImmutable;

/**
 * Seven days back (older periods may be invoiced, ADR-0010) and a few minutes
 * ahead for clock drift. Seven days also means seven writable daily partitions
 * (ADR-0002).
 */
final readonly class AcceptanceWindow
{
    private function __construct(
        public int $maxAgeSeconds,
        public int $maxDriftSeconds,
    ) {}

    public static function of(int $maxAgeSeconds, int $maxDriftSeconds): self
    {
        return new self(max(0, $maxAgeSeconds), max(0, $maxDriftSeconds));
    }

    /**
     * Null to accept. Both edges are inclusive.
     */
    public function reasonToReject(DateTimeImmutable $occurredAt, DateTimeImmutable $now): ?RejectionReason
    {
        $age = $now->getTimestamp() - $occurredAt->getTimestamp();

        if ($age > $this->maxAgeSeconds) {
            return RejectionReason::TooOld;
        }

        return -$age > $this->maxDriftSeconds ? RejectionReason::InTheFuture : null;
    }
}
