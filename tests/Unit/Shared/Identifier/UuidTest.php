<?php

declare(strict_types=1);

use Metered\Shared\Domain\Exception\InvalidIdentifier;
use Metered\Shared\Domain\Identifier\Uuid;

it('accepts a well formed identifier', function (string $value): void {
    expect(Uuid::fromString($value)->value)->toBe(strtolower(trim($value)));
})->with([
    'v4' => ['9f8e7d6c-5b4a-4938-8271-605f4e3d2c1b'],
    'v7' => ['01932b1c-4f00-7000-8000-0123456789ab'],
    'uppercase is normalised' => ['9F8E7D6C-5B4A-4938-8271-605F4E3D2C1B'],
    'surrounding whitespace is trimmed' => ["  9f8e7d6c-5b4a-4938-8271-605f4e3d2c1b\n"],
]);

it('rejects anything that is not an identifier', function (string $value): void {
    expect(static fn(): Uuid => Uuid::fromString($value))
        ->toThrow(InvalidIdentifier::class);
})->with([
    'empty' => [''],
    'not hexadecimal' => ['zzzzzzzz-5b4a-4938-8271-605f4e3d2c1b'],
    'too short' => ['9f8e7d6c-5b4a-4938-8271-605f4e3d2c1'],
    'too long' => ['9f8e7d6c-5b4a-4938-8271-605f4e3d2c1bb'],
    'no hyphens' => ['9f8e7d6c5b4a49388271605f4e3d2c1b'],
    'nil uuid has no version' => ['00000000-0000-0000-0000-000000000000'],
    'version 0 does not exist' => ['9f8e7d6c-5b4a-0938-8271-605f4e3d2c1b'],
    'reserved variant' => ['9f8e7d6c-5b4a-4938-c271-605f4e3d2c1b'],
    'a sentence' => ['not an identifier at all'],
]);

it('reports the version it carries', function (): void {
    expect(Uuid::fromString('01932b1c-4f00-7000-8000-0123456789ab')->version())->toBe(7)
        ->and(Uuid::fromString('9f8e7d6c-5b4a-4938-8271-605f4e3d2c1b')->version())->toBe(4);
});

it('answers whether a string would be accepted without throwing', function (): void {
    expect(Uuid::isValid('01932b1c-4f00-7000-8000-0123456789ab'))->toBeTrue()
        ->and(Uuid::isValid('nope'))->toBeFalse();
});

it('normalises before validating, exactly as the constructor does', function (string $value): void {
    expect(Uuid::isValid($value))->toBeTrue();
})->with([
    'uppercase' => ['01932B1C-4F00-7000-8000-0123456789AB'],
    'mixed case' => ['01932b1C-4F00-7000-8000-0123456789aB'],
    'padded with whitespace' => ["\t 01932b1c-4f00-7000-8000-0123456789ab \n"],
]);

it('compares by value, not by instance', function (): void {
    $one = Uuid::fromString('01932b1c-4f00-7000-8000-0123456789ab');
    $same = Uuid::fromString('01932B1C-4F00-7000-8000-0123456789AB');
    $other = Uuid::fromString('01932b1c-4f00-7000-8000-0123456789ac');

    expect($one->equals($same))->toBeTrue()
        ->and($one->equals($other))->toBeFalse();
});

it('renders as its canonical string', function (): void {
    $uuid = Uuid::fromString('01932B1C-4F00-7000-8000-0123456789AB');

    expect((string) $uuid)->toBe('01932b1c-4f00-7000-8000-0123456789ab');
});
