<?php

declare(strict_types=1);

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Domain\Exception\InvalidTenantName;
use Metered\Tenancy\Domain\Organization;
use Metered\Tenancy\Domain\Slug;

it('is registered with a name, a slug and the moment it was created', function (): void {
    $organization = Organization::register(
        Uuid::fromString('01924b7c-0000-7000-8000-00000000000a'),
        'Acme, Inc.',
        Slug::fromString('acme'),
        new DateTimeImmutable('2026-09-13T12:00:00+00:00'),
    );

    expect($organization->name)->toBe('Acme, Inc.')
        ->and($organization->slug->value)->toBe('acme')
        ->and($organization->createdAt->format(DATE_RFC3339))->toBe('2026-09-13T12:00:00+00:00');
});

it('trims the name it is given', function (): void {
    expect(organization('  Acme  ')->name)->toBe('Acme');
});

it('refuses a name that says nothing', function (string $name): void {
    expect(static fn(): Organization => organization($name))
        ->toThrow(InvalidTenantName::class, 'cannot be empty');
})->with(['', '   ', "\t\n"]);

it('accepts a name of exactly the length the column holds', function (): void {
    expect(organization(str_repeat('a', 120))->name)->toHaveLength(120);
});

it('refuses a name one character longer than the column that stores it', function (): void {
    expect(static fn(): Organization => organization(str_repeat('a', 121)))
        ->toThrow(InvalidTenantName::class, 'at most 120');
});

function organization(string $name): Organization
{
    return Organization::register(
        Uuid::fromString('01924b7c-0000-7000-8000-00000000000a'),
        $name,
        Slug::fromString('acme'),
        new DateTimeImmutable('2026-09-13T12:00:00+00:00'),
    );
}
