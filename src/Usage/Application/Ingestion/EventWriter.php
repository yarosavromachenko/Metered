<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Ingestion;

use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * Writes events and their aggregates, in one transaction, exactly once in
 * effect.
 *
 * One method, because the two halves must not be callable separately:
 * aggregates are folded from the rows the insert actually created, and a
 * caller able to do one without the other is a caller able to double-count
 * (ADR-0004).
 */
interface EventWriter
{
    /**
     * @param  list<ResolvedEvent>  $events
     */
    public function write(TenantContext $tenant, array $events): WriteOutcome;
}
