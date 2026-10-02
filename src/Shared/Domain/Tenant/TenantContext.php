<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Tenant;

use Metered\Shared\Domain\Identifier\Uuid;
use Stringable;

/**
 * Organization and project a query is scoped to (ADR-0013). Passed explicitly
 * to repositories; there is no "current tenant" singleton, which would be
 * empty in queued jobs and stale in Octane workers.
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
