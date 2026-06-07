<?php

declare(strict_types=1);

use Metered\Shared\Domain\Exception\InvalidDecimal;
use Metered\Shared\Domain\Quantity\Quantity;

it('carries six decimal places', function (string $given, string $expected): void {
    expect((string) Quantity::fromString($given))->toBe($expected);
})->with([
    'whole number' => ['5', '5.000000'],
    'one place' => ['1.5', '1.500000'],
    'exactly six places' => ['0.000001', '0.000001'],
    'zero' => ['0', '0.000000'],
    'surrounding whitespace' => ['  2.25  ', '2.250000'],
    'trailing zeros are kept at scale' => ['3.100000', '3.100000'],
    // Accepted on purpose: the decimal parser reads it exactly, and a client
    // serialising 1000 as 1e3 is sending a number, not a mistake.
    'scientific notation' => ['1e3', '1000.000000'],
]);

it('refuses a value carrying more precision than it can store', function (): void {
    expect(static fn(): Quantity => Quantity::fromString('0.0000001'))
        ->toThrow(InvalidDecimal::class, 'silently change what a customer is billed');
});

it('refuses a negative amount', function (): void {
    // Usage is something that happened. Taking it back is a credit note, with
    // its own document and its own ledger entries.
    expect(static fn(): Quantity => Quantity::fromString('-1'))
        ->toThrow(InvalidDecimal::class, 'is negative');
});

it('refuses anything that is not a number', function (string $given): void {
    expect(static fn(): Quantity => Quantity::fromString($given))
        ->toThrow(InvalidDecimal::class, 'is not a decimal number');
})->with([
    'empty' => [''],
    'words' => ['many'],
    'trailing unit' => ['12kb'],
    'thousands separator' => ['1,000'],
    'hexadecimal' => ['0x10'],
]);

it('states the scale its column has to hold', function (): void {
    // numeric(20, 6) in the migration comes from here.
    expect(Quantity::SCALE)->toBe(6);
});

it('starts at zero', function (): void {
    expect(Quantity::zero()->isZero())->toBeTrue()
        ->and((string) Quantity::zero())->toBe('0.000000');
});

it('adds without losing precision', function (): void {
    $sum = Quantity::fromString('0.000001')->plus(Quantity::fromString('0.000002'));

    expect((string) $sum)->toBe('0.000003');
});

it('keeps the larger of two quantities, which is how max meters aggregate', function (): void {
    $small = Quantity::fromString('1.5');
    $large = Quantity::fromString('10');

    expect((string) $small->max($large))->toBe('10.000000')
        ->and((string) $large->max($small))->toBe('10.000000')
        ->and((string) $small->max(Quantity::fromString('1.5')))->toBe('1.500000');
});

it('orders quantities', function (): void {
    $small = Quantity::fromString('1.5');
    $large = Quantity::fromString('1.500001');

    expect($small->compareTo($large))->toBeLessThan(0)
        ->and($large->compareTo($small))->toBeGreaterThan(0)
        ->and($small->compareTo(Quantity::fromString('1.5')))->toBe(0);
});

it('treats the same value written differently as equal', function (): void {
    expect(Quantity::fromString('1.5')->equals(Quantity::fromString('1.500000')))->toBeTrue()
        ->and(Quantity::fromString('2')->equals(Quantity::fromString('2.000000')))->toBeTrue()
        ->and(Quantity::fromString('2')->equals(Quantity::fromString('2.000001')))->toBeFalse();
});
