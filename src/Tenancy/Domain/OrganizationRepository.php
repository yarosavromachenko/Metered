<?php

declare(strict_types=1);

namespace Metered\Tenancy\Domain;

use DateTimeImmutable;
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

    /**
     * Deletes the organization row; its projects, keys and memberships follow
     * it by cascade. Everything the other modules hold must be gone first.
     */
    public function remove(Uuid $id): void;

    /**
     * Demo organizations nobody has signed in to since the cutoff, oldest
     * first. A member who never signed in counts from when they registered.
     *
     * @return list<Uuid>
     */
    public function demosIdleSince(DateTimeImmutable $cutoff): array;
}
