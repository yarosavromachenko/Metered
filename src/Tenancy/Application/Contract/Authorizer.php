<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Contract;

use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Access\PermissionDenied;
use Metered\Shared\Domain\Identifier\Uuid;

/**
 * Handlers call {@see self::ensure()}; screens call {@see self::allows()} to
 * decide whether to show an action (ADR-0017).
 */
interface Authorizer
{
    /**
     * @throws PermissionDenied when the actor's membership does not carry it
     */
    public function ensure(Actor $actor, Uuid $organizationId, Permission $permission): void;

    public function allows(Actor $actor, Uuid $organizationId, Permission $permission): bool;
}
