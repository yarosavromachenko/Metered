<?php

declare(strict_types=1);

namespace Metered\Tenancy\Domain;

use Metered\Shared\Domain\Identifier\Uuid;

/**
 * Organizations are the top of the tenant tree, so this is the one repository
 * in the system whose methods do not take a tenant context: there is nothing
 * above an organization to scope it by.
 */
interface OrganizationRepository
{
    public function save(Organization $organization): void;

    public function find(Uuid $id): ?Organization;

    public function findBySlug(Slug $slug): ?Organization;
}
