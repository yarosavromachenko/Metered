<?php

declare(strict_types=1);

use Metered\Tenancy\Domain\Exception\InvalidSlug;
use Metered\Tenancy\Domain\Slug;

it('accepts a slug that is already in the shape it needs', function (string $value): void {
    expect(Slug::fromString($value)->value)->toBe($value);
})->with(['acme', 'acme-billing', 'a1', 'north-wind-2026']);

it('normalises what it is given', function (string $given, string $expected): void {
    expect(Slug::fromString($given)->value)->toBe($expected);
})->with([
    ['ACME', 'acme'],
    ['  acme-billing  ', 'acme-billing'],
]);

it('refuses a shape that would not survive a URL', function (string $value): void {
    expect(static fn(): Slug => Slug::fromString($value))->toThrow(InvalidSlug::class);
})->with([
    'a',                    // too short to be distinguishable
    '-acme',                // leading separator
    'acme-',                // trailing separator
    'acme--billing',        // doubled separator
    'acme billing',         // whitespace inside
    'acme_billing',         // underscore is not a separator here
    'acme.billing',
    'ACME/billing',
    '',
]);

it('refuses a slug longer than a database label', function (): void {
    expect(static fn(): Slug => Slug::fromString(str_repeat('a', 64)))
        ->toThrow(InvalidSlug::class, 'at most 63');
});

it('builds a slug out of the name a human typed', function (string $name, string $expected): void {
    expect(Slug::fromName($name)->value)->toBe($expected);
})->with([
    ['Acme, Inc.', 'acme-inc'],
    ['North Wind  Billing', 'north-wind-billing'],
    ['Überwald GmbH', 'uberwald-gmbh'],
    ['2026 Q1 — pilot', '2026-q1-pilot'],
    ['Acme!!!', 'acme'],
]);

it('gives up on a name with nothing to build from', function (string $name): void {
    expect(static fn(): Slug => Slug::fromName($name))->toThrow(InvalidSlug::class);
})->with(['!!!', '   ', 'é']);

it('is equal to the same slug and to nothing else', function (): void {
    expect(Slug::fromString('acme')->equals(Slug::fromString('ACME')))->toBeTrue()
        ->and(Slug::fromString('acme')->equals(Slug::fromString('acme-billing')))->toBeFalse();
});
