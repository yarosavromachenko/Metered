<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Contract;

use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Access\PermissionDenied;
use Metered\Shared\Domain\Identifier\Uuid;

/**
 * What another module asks before it lets an actor change anything.
 *
 * Tenancy owns memberships and roles; every other module owns work that only
 * some people may do. This interface is the whole of what they need from each
 * other — an answer about one actor, one organization and one permission —
 * and it is published here because a Billing handler may not reach into
 * Tenancy's domain to ask.
 *
 * Two methods, not one: a handler refuses, so it calls {@see self::ensure()};
 * a screen decides whether to offer a button at all, so it calls
 * {@see self::allows()}. Hiding the button is a courtesy; the refusal is the
 * control (ADR-0017).
 */
interface Authorizer
{
    /**
     * @throws PermissionDenied when the actor's membership does not carry it
     */
    public function ensure(Actor $actor, Uuid $organizationId, Permission $permission): void;

    public function allows(Actor $actor, Uuid $organizationId, Permission $permission): bool;
}
