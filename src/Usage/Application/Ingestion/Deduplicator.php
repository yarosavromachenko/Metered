<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Ingestion;

use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Usage\Domain\UsageEvent;

/**
 * The deduplication layer the database cannot provide.
 *
 * The unique index catches an exact resend — same project, same event id,
 * same `occurred_at` — and cannot catch anything else, because a unique index
 * on a partitioned table must contain the partition key (ADR-0002). A client
 * that resends an event with a corrected timestamp would therefore be counted
 * twice, and this is what stops it.
 *
 * It stops that and nothing more. A claim records the `occurred_at` it was
 * taken for, and an event arriving under a claim with the same timestamp is
 * passed through for the database to decide. That is what keeps a claim
 * from outliving the write it was taken for: a consumer killed after claiming
 * and before committing leaves its claims behind, and the redelivery has to
 * reach the database rather than be dismissed as a duplicate of an event that
 * was never written.
 *
 * Claims expire: the guarantee is "not twice within the window", not "never
 * twice", and the window is the acceptance window, because that is how long a
 * resend can still matter.
 */
interface Deduplicator
{
    /**
     * Claims the events that have not been seen, and returns the ones the
     * database should be asked about: every event claimed now, and every
     * event whose claim was taken for the same `occurred_at`.
     *
     * Atomic per event: two consumers processing the same redelivered batch
     * must not both come away thinking they claimed it for different times.
     *
     * @param  list<UsageEvent>  $events
     * @return list<UsageEvent>
     */
    public function claim(TenantContext $tenant, array $events): array;
}
