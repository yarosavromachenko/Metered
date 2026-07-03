<?php

declare(strict_types=1);

namespace Metered\Usage\Domain;

use DateTimeImmutable;

/**
 * How far from now an event's own timestamp may sit and still be counted.
 *
 * Asymmetric, and both halves have a reason. Backwards, the bound is the
 * period that may already have been invoiced: an event older than the window
 * cannot change a finalized invoice (ADR-0010), so it is rejected with a
 * recorded reason rather than dropped — a tenant whose totals are short must
 * have something to look at. Forwards, the bound is clock drift: a client
 * running a couple of minutes fast is a fact of life, not an error, but an
 * event dated next month would sit unbilled in a future period.
 *
 * The window is also what makes daily partitions the right size (ADR-0002):
 * seven days of acceptance is seven partitions that can still take writes.
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
     * The reason to reject this event, or null to accept it. Both edges are
     * inclusive: an event exactly seven days old is in, because a boundary
     * that depends on which microsecond a batch was read in is a boundary
     * nobody can reason about.
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
