<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Authorization;

use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Access\PermissionDenied;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Application\Contract\Authorizer;
use Metered\Tenancy\Domain\MembershipRepository;

/**
 * {@see Authorizer} over memberships. Handlers call it, so a direct call
 * (URL, replayed request, Livewire message) is refused even when the UI hides
 * the button (ADR-0017). No membership in the organization means no
 * permissions.
 */
final readonly class PermissionGuard implements Authorizer
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
            // System actors (console, scheduler) are the operator.
            return true;
        }

        return $this->memberships->find($organizationId, $actor->userId)?->may($permission) === true;
    }
}
