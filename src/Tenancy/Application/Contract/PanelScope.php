<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Contract;

use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Tenant\TenantContext;
use RuntimeException;

/**
 * The organization and project selected in the panel, validated against
 * memberships. `null` means the user has no organization yet; screens must
 * not treat it as "no filter".
 */
interface PanelScope
{
    public function tenant(): ?TenantContext;

    public function may(Permission $permission): bool;

    /**
     * From the auth guard, never from request input.
     *
     * @throws RuntimeException when nobody is signed in
     */
    public function actor(): Actor;
}
