<?php

declare(strict_types=1);

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Domain\Membership;
use Metered\Tenancy\Domain\Permission;
use Metered\Tenancy\Domain\Role;

it('lets every role read the organization it belongs to', function (Role $role): void {
    expect($role->may(Permission::ViewOrganization))->toBeTrue();
})->with(Role::cases());

it('gives the owner everything', function (Permission $permission): void {
    expect(Role::Owner->may($permission))->toBeTrue();
})->with(Permission::cases());

it('keeps catalog authority and money authority in different hands', function (): void {
    expect(Role::Admin->may(Permission::ManageCatalog))->toBeTrue()
        ->and(Role::Admin->may(Permission::MoveMoney))->toBeFalse()
        ->and(Role::BillingOperator->may(Permission::MoveMoney))->toBeTrue()
        ->and(Role::BillingOperator->may(Permission::ManageCatalog))->toBeFalse();
});

it('overlaps on reads and is disjoint on writes', function (): void {
    $admin = writesOf(Role::Admin);
    $operator = writesOf(Role::BillingOperator);

    expect(array_intersect($admin, $operator))->toBe([])
        ->and($admin)->not->toBe([])
        ->and($operator)->not->toBe([])
        ->and(Role::Admin->may(Permission::ViewOrganization))->toBeTrue()
        ->and(Role::BillingOperator->may(Permission::ViewOrganization))->toBeTrue();
});

it('lets nobody but the owner change who has access', function (Role $role): void {
    expect($role->may(Permission::ManageTenant))->toBe($role === Role::Owner);
})->with(Role::cases());

it('gives a viewer nothing to change', function (): void {
    expect(Role::Viewer->permissions())->toBe([Permission::ViewOrganization]);
});

it('answers on behalf of the membership that carries it', function (): void {
    $organization = Uuid::fromString('01924b7c-0000-7000-8000-0000000000d1');
    $membership = new Membership(
        Uuid::fromString('01924b7c-0000-7000-8000-0000000000d0'),
        $organization,
        Uuid::fromString('01924b7c-0000-7000-8000-0000000000d2'),
        Role::BillingOperator,
        new DateTimeImmutable('2026-09-18T10:00:00+00:00'),
    );

    expect($membership->may(Permission::MoveMoney))->toBeTrue()
        ->and($membership->may(Permission::ManageTenant))->toBeFalse()
        ->and($membership->isIn($organization))->toBeTrue()
        ->and($membership->isIn(Uuid::fromString('01924b7c-0000-7000-8000-0000000000d3')))->toBeFalse();
});

/**
 * The permissions of a role that let it change something, as plain strings —
 * array_intersect compares as strings, and enum cases do not convert.
 *
 * @return list<string>
 */
function writesOf(Role $role): array
{
    $writes = array_filter(
        $role->permissions(),
        static fn(Permission $permission): bool => $permission !== Permission::ViewOrganization,
    );

    return array_values(array_map(static fn(Permission $permission): string => $permission->value, $writes));
}
