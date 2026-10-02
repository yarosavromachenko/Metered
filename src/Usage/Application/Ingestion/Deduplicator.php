<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Ingestion;

use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Usage\Domain\UsageEvent;

/**
 * Catches a resend with a different `occurred_at`, which the unique index
 * cannot: on a partitioned table it must include the partition key
 * (ADR-0002). A claim stores its `occurred_at`; a same-timestamp event passes
 * through so the database decides (needed after a crash before commit).
 * Claims expire with the acceptance window.
 */
interface Deduplicator
{
    /**
     * Atomic per event. Returns newly claimed events and those whose claim
     * has the same `occurred_at`.
     *
     * @param  list<UsageEvent>  $events
     * @return list<UsageEvent>
     */
    public function claim(TenantContext $tenant, array $events): array;
}
