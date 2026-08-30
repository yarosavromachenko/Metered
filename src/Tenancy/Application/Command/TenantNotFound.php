<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use RuntimeException;

/**
 * Raised when a command names something the caller's tenant cannot see.
 *
 * "Not found" rather than "forbidden", deliberately: the lookups are scoped,
 * so a key belonging to another organization is indistinguishable from one
 * that never existed — and telling those apart would confirm the existence of
 * rows the caller has no business knowing about.
 */
final class TenantNotFound extends RuntimeException
{
    public static function project(TenantContext $tenant): self
    {
        return new self(sprintf('No project %s in this organization.', $tenant->projectId->value));
    }

    public static function organization(Uuid $id): self
    {
        return new self(sprintf('No organization %s.', $id->value));
    }

    public static function apiKey(Uuid $id): self
    {
        return new self(sprintf('No API key %s in this project.', $id->value));
    }
}
