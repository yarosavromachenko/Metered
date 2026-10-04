<?php

declare(strict_types=1);

namespace Metered\Tenancy\Domain;

use Metered\Shared\Domain\Access\Permission;

/**
 * ADR-0017. `admin` (catalog) and `billing_operator` (money) are not nested:
 * they share reads and have disjoint writes.
 */
enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case BillingOperator = 'billing_operator';
    case Viewer = 'viewer';

    public function may(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::BillingOperator => 'Billing operator',
            self::Viewer => 'Viewer',
        };
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),
            self::Admin => [
                Permission::ViewOrganization,
                Permission::ManageCatalog,
                Permission::OperateWebhooks,
            ],
            self::BillingOperator => [
                Permission::ViewOrganization,
                Permission::MoveMoney,
            ],
            self::Viewer => [Permission::ViewOrganization],
        };
    }
}
