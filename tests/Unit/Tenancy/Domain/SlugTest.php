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

it('accepts the shortest and the longest slug there is', function (): void {
    expect(Slug::fromString('ab')->value)->toBe('ab')
        ->and(Slug::fromString(str_repeat('a', 63))->value)->toHaveLength(63);
});

it('refuses a slug one character outside either bound', function (): void {
    expect(static fn(): Slug => Slug::fromString('a'))
        ->toThrow(InvalidSlug::class, 'at least 2')
        ->and(static fn(): Slug => Slug::fromString(str_repeat('a', 64)))
        ->toThrow(InvalidSlug::class, 'at most 63');
});

it('truncates a long name to the longest slug that fits', function (): void {
    // Cut at the limit, then the hyphen the cut left behind is removed: the
    // result is a valid slug rather than one fromString would reject.
    $slug = Slug::fromName(str_repeat('ab ', 40));

    expect(strlen($slug->value))->toBeLessThanOrEqual(63)
        ->and($slug->value)->not->toEndWith('-')
        ->and($slug->value)->toStartWith('ab-ab');
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

it('builds the shortest slug a name can yield', function (): void {
    expect(Slug::fromName('AB')->value)->toBe('ab');
});

it('keeps a name that is already exactly as long as a slug may be', function (): void {
    expect(Slug::fromName(str_repeat('a', 63))->value)->toHaveLength(63);
});

it('lowercases what the transliteration left in upper case', function (): void {
    expect(Slug::fromName('ACME GmbH')->value)->toBe('acme-gmbh');
});

it('is equal to the same slug and to nothing else', function (): void {
    expect(Slug::fromString('acme')->equals(Slug::fromString('ACME')))->toBeTrue()
        ->and(Slug::fromString('acme')->equals(Slug::fromString('acme-billing')))->toBeFalse();
});
