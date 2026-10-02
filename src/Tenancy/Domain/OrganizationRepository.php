<?php

declare(strict_types=1);

namespace Metered\Tenancy\Domain;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;

/**
 * No tenant context: organizations are the top of the tenant tree.
 */
interface OrganizationRepository
{
    public function save(Organization $organization): void;

    public function find(Uuid $id): ?Organization;

    public function findBySlug(Slug $slug): ?Organization;

    /**
     * Projects, keys and memberships cascade. Other modules' rows must be
     * deleted first.
     */
    public function remove(Uuid $id): void;

    /**
     * Oldest first. With a cutoff, only those without a sign-in since then (a
     * member who never signed in counts from registration).
     *
     * @return list<Uuid>
     */
    public function demos(?DateTimeImmutable $idleSince = null): array;
}
