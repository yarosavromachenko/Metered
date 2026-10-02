<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Ingestion;

use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * Events and aggregates in one transaction; aggregates come only from rows the
 * insert created (ADR-0004).
 */
interface EventWriter
{
    /**
     * @param  list<ResolvedEvent>  $events
     */
    public function write(TenantContext $tenant, array $events): WriteOutcome;
}
