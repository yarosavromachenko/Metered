<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Domain\Slug;
use RuntimeException;

/**
 * Also for another tenant's rows: "forbidden" would confirm they exist.
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

    public static function organizationNamed(Slug $slug): self
    {
        return new self(sprintf('No organization "%s".', $slug->value));
    }

    public static function apiKey(Uuid $id): self
    {
        return new self(sprintf('No API key %s in this project.', $id->value));
    }
}
