<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Authorization;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Domain\MembershipRepository;
use Metered\Tenancy\Domain\Permission;

/**
 * Authorization at the handler boundary, which is the only boundary that
 * counts.
 *
 * Hiding a button is a courtesy to the person looking at the screen; it is not
 * a control. An `admin` who calls an owner-only handler directly — by URL, by
 * a replayed request, by a Livewire message — is refused here, and ADR-0017
 * says so explicitly.
 *
 * Membership is per organization, so every check names one. A person with no
 * membership in the organization they are asking about is refused for the same
 * reason as a person with the wrong role: the guard asks what the membership
 * permits, and no membership permits nothing.
 */
final readonly class PermissionGuard
{
    public function __construct(private MembershipRepository $memberships) {}

    public function ensure(Actor $actor, Uuid $organizationId, Permission $permission): void
    {
        if (! $this->allows($actor, $organizationId, $permission)) {
            throw PermissionDenied::for($actor, $permission);
        }
    }

    public function allows(Actor $actor, Uuid $organizationId, Permission $permission): bool
    {
        if (!$actor->userId instanceof Uuid) {
            // The console and the scheduler answer to whoever can run them,
            // which is the operator of the installation.
            return true;
        }

        return $this->memberships->find($organizationId, $actor->userId)?->may($permission) === true;
    }
}
