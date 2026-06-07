<?php

declare(strict_types=1);

use Metered\Shared\Domain\Exception\CurrencyMismatch;
use Metered\Shared\Domain\Exception\InvalidMoney;
use Metered\Shared\Domain\Money\Money;

it('is built from minor units', function (): void {
    $money = Money::ofMinorUnits(1999, 'EUR');

    expect($money->minorUnits())->toBe(1999)
        ->and($money->currency())->toBe('EUR')
        ->and((string) $money)->toBe('19.99 EUR');
});

it('normalises the currency code it is given', function (string $given): void {
    expect(Money::ofMinorUnits(100, $given)->currency())->toBe('EUR');
})->with(['eur', 'EUR', ' eur ', 'Eur']);

it('refuses a currency that does not exist', function (): void {
    expect(static fn(): Money => Money::ofMinorUnits(100, 'XYZ'))
        ->toThrow(InvalidMoney::class, 'not a known ISO 4217 currency');
});

it('respects the minor unit of the currency', function (): void {
    // The yen has no minor unit: 500 minor units are 500 yen, not 5.00.
    expect((string) Money::ofMinorUnits(500, 'JPY'))->toBe('500 JPY')
        ->and((string) Money::ofMinorUnits(500, 'EUR'))->toBe('5.00 EUR');
});

it('starts at zero', function (): void {
    $zero = Money::zero('EUR');

    expect($zero->minorUnits())->toBe(0)
        ->and($zero->isZero())->toBeTrue()
        ->and($zero->isPositive())->toBeFalse()
        ->and($zero->isNegative())->toBeFalse();
});

it('adds and subtracts exactly, with no rounding of its own', function (): void {
    $a = Money::ofMinorUnits(1999, 'EUR');
    $b = Money::ofMinorUnits(1, 'EUR');

    expect($a->plus($b)->minorUnits())->toBe(2000)
        ->and($a->minus($b)->minorUnits())->toBe(1998)
        ->and($a->minorUnits())->toBe(1999);
});

it('goes negative when it has to, because a ledger has two sides', function (): void {
    $owed = Money::ofMinorUnits(500, 'EUR')->minus(Money::ofMinorUnits(800, 'EUR'));

    expect($owed->minorUnits())->toBe(-300)
        ->and($owed->isNegative())->toBeTrue()
        ->and($owed->negated()->minorUnits())->toBe(300);
});

it('never adds two currencies', function (): void {
    $euros = Money::ofMinorUnits(100, 'EUR');
    $dollars = Money::ofMinorUnits(100, 'USD');

    expect(static fn(): Money => $euros->plus($dollars))
        ->toThrow(CurrencyMismatch::class, 'never converts between currencies');
});

it('never subtracts two currencies', function (): void {
    $euros = Money::ofMinorUnits(100, 'EUR');
    $dollars = Money::ofMinorUnits(100, 'USD');

    expect(static fn(): Money => $euros->minus($dollars))
        ->toThrow(CurrencyMismatch::class, 'never converts between currencies');
});

it('never orders two currencies, because the question has no answer', function (): void {
    $euros = Money::ofMinorUnits(100, 'EUR');
    $dollars = Money::ofMinorUnits(100, 'USD');

    expect(static fn(): int => $euros->compareTo($dollars))
        ->toThrow(CurrencyMismatch::class, 'never converts between currencies');
});

it('orders amounts of the same currency', function (): void {
    $small = Money::ofMinorUnits(100, 'EUR');
    $large = Money::ofMinorUnits(250, 'EUR');

    expect($small->compareTo($large))->toBeLessThan(0)
        ->and($large->compareTo($small))->toBeGreaterThan(0)
        ->and($small->compareTo(Money::ofMinorUnits(100, 'EUR')))->toBe(0);
});

it('is equal only to the same amount in the same currency', function (): void {
    $euros = Money::ofMinorUnits(100, 'EUR');

    expect($euros->equals(Money::ofMinorUnits(100, 'EUR')))->toBeTrue()
        ->and($euros->equals(Money::ofMinorUnits(101, 'EUR')))->toBeFalse()
        // Comparing across currencies is a question with no answer, but asking
        // whether they are equal has one, and it is "no".
        ->and($euros->equals(Money::ofMinorUnits(100, 'USD')))->toBeFalse();
});
