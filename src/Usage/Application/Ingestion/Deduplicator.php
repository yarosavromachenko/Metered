<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Ingestion;

use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Usage\Domain\EventId;

/**
 * The deduplication layer the database cannot provide.
 *
 * The unique index catches an exact resend — same project, same event id,
 * same `occurred_at` — and cannot catch anything else, because a unique index
 * on a partitioned table must contain the partition key (ADR-0002). A client
 * that resends an event with a corrected timestamp would therefore be counted
 * twice, and this is what stops it.
 *
 * Claims expire: the guarantee is "not twice within the window", not "never
 * twice", and the window is the acceptance window, because that is how long a
 * resend can still matter.
 */
interface Deduplicator
{
    /**
     * Claims the ids that have not been seen, and returns those.
     *
     * Atomic per id: two consumers processing the same redelivered batch must
     * not both come away thinking they claimed it.
     *
     * @param  list<EventId>  $eventIds
     * @return list<EventId>  the ones this caller now owns
     */
    public function claim(TenantContext $tenant, array $eventIds): array;

    /**
     * Gives claims back, for when the write they were claimed for did not
     * happen.
     *
     * Without this a failed transaction would leave the ids claimed, the
     * redelivery would be treated as a duplicate, and the events would be
     * lost — the one outcome the whole design exists to prevent.
     *
     * @param  list<EventId>  $eventIds
     */
    public function release(TenantContext $tenant, array $eventIds): void;
}
