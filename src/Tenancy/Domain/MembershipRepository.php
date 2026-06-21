<?php

declare(strict_types=1);

namespace Metered\Tenancy\Domain;

use Metered\Shared\Domain\Identifier\Uuid;

interface MembershipRepository
{
    public function save(Membership $membership): void;

    public function find(Uuid $organizationId, Uuid $userId): ?Membership;

    /**
     * Every organization this person belongs to — the list behind the panel's
     * organization switcher.
     *
     * @return list<Membership>
     */
    public function forUser(Uuid $userId): array;

    /**
     * @return list<Membership>
     */
    public function forOrganization(Uuid $organizationId): array;
}
