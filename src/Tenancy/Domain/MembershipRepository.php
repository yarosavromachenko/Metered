<?php

declare(strict_types=1);

namespace Metered\Tenancy\Domain;

use Metered\Shared\Domain\Identifier\Uuid;

interface MembershipRepository
{
    public function save(Membership $membership): void;

    public function find(Uuid $organizationId, Uuid $userId): ?Membership;

    /**
     * @return list<Membership>
     */
    public function forUser(Uuid $userId): array;

    /**
     * @return list<Membership>
     */
    public function forOrganization(Uuid $organizationId): array;
}
