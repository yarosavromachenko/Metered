<?php

declare(strict_types=1);

namespace Metered\Tenancy\Domain;

use DateTimeImmutable;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Identifier\Uuid;

/**
 * A person's place in one organization.
 *
 * Membership is where authorization starts: a user is nothing on their own,
 * and everything the panel allows is a question about the membership that
 * connects them to the organization they are currently looking at.
 */
final readonly class Membership
{
    public function __construct(
        public Uuid $id,
        public Uuid $organizationId,
        public Uuid $userId,
        public Role $role,
        public DateTimeImmutable $createdAt,
    ) {}

    public function may(Permission $permission): bool
    {
        return $this->role->may($permission);
    }

    public function isIn(Uuid $organizationId): bool
    {
        return $this->organizationId->equals($organizationId);
    }
}
