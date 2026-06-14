<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Tenant;

use Metered\Shared\Domain\Identifier\Uuid;
use Stringable;

/**
 * Whose data a piece of work is allowed to touch.
 *
 * Every tenant-owned row carries an organization and a project, and every
 * query filters by both (ADR-0013). This type exists so that filter can be a
 * parameter rather than a convention: a repository method that needs scoping
 * asks for a TenantContext, and a caller that has not got one cannot invent it
 * by accident.
 *
 * It lives in the shared kernel rather than in Tenancy because every module's
 * domain speaks it, and a Usage repository may not import Tenancy's internals.
 *
 * Deliberately not resolvable from ambient state. A "current tenant" singleton
 * is empty inside a queued job and stale inside an Octane worker, and both
 * failures are silent — the query still runs, against the wrong rows.
 */
final readonly class TenantContext implements Stringable
{
    public function __construct(
        public Uuid $organizationId,
        public Uuid $projectId,
    ) {}

    public function __toString(): string
    {
        return $this->organizationId->value . '/' . $this->projectId->value;
    }

    public function equals(self $other): bool
    {
        return $this->organizationId->equals($other->organizationId)
            && $this->projectId->equals($other->projectId);
    }
}
