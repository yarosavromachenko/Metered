<?php

declare(strict_types=1);

namespace Metered\Tenancy\Domain;

/**
 * What a person may do inside one organization (ADR-0017).
 *
 * `admin` and `billing_operator` are deliberately not nested. Shaping the
 * catalog and deciding what a customer is charged are different kinds of
 * authority, and in a real billing organization they usually belong to
 * different people: one decides what a thing costs, the other signs off on the
 * invoice that says someone owes it.
 *
 * That split is also what makes these rules testable. The two roles overlap on
 * reads and are disjoint on writes, so a policy that quietly grants everything
 * to everyone fails a test instead of passing unnoticed.
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
