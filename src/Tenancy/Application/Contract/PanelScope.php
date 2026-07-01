<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Contract;

use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * What the person at the keyboard is currently looking at, for the screens
 * that belong to other modules.
 *
 * Every admin screen is scoped to one organization and one project, and no
 * module may derive that for itself: the scope is a session choice validated
 * against memberships, which is Tenancy's business. A Billing screen asks this
 * and filters by the answer.
 *
 * `null` is a real answer — a person who belongs to no organization yet. A
 * screen that treats it as "no filter" would show every tenant's data, so the
 * type makes the empty case impossible to overlook.
 */
interface PanelScope
{
    public function tenant(): ?TenantContext;

    public function may(Permission $permission): bool;
}
