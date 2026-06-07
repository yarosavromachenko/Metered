<?php

declare(strict_types=1);

use Metered\Shared\Domain\Exception\InvalidDecimal;
use Metered\Shared\Domain\Exception\InvalidMoney;
use Metered\Shared\Domain\Money\UnitPrice;
use Metered\Shared\Domain\Quantity\Quantity;

it('carries eight decimal places, because a unit price is often a fraction of a cent', function (): void {
    $price = UnitPrice::fromString('0.00012', 'EUR');

    expect((string) $price)->toBe('0.00012000 EUR')
        ->and($price->currency())->toBe('EUR');
});

it('normalises the currency code it is given', function (string $given): void {
    expect(UnitPrice::fromString('1.00', $given)->currency())->toBe('EUR');
})->with(['eur', 'EUR', ' eur ', 'Eur']);

it('refuses a price it cannot store exactly', function (): void {
    expect(static fn(): UnitPrice => UnitPrice::fromString('0.000000001', 'EUR'))
        ->toThrow(InvalidDecimal::class, 'more than 8 decimal places');
});

it('refuses a negative price and an unknown currency', function (): void {
    expect(static fn(): UnitPrice => UnitPrice::fromString('-0.01', 'EUR'))
        ->toThrow(InvalidDecimal::class, 'is negative')
        ->and(static fn(): UnitPrice => UnitPrice::fromString('0.01', 'ZZZ'))
        ->toThrow(InvalidMoney::class, 'not a known ISO 4217 currency');
});

it('multiplies a price by a quantity and rounds once, half up', function (
    string $price,
    string $quantity,
    string $currency,
    int $expectedMinorUnits,
): void {
    $total = UnitPrice::fromString($price, $currency)->multipliedBy(Quantity::fromString($quantity));

    expect($total->minorUnits())->toBe($expectedMinorUnits)
        ->and($total->currency())->toBe($currency);
})->with([
    // The case the eight decimal places exist for: a thousand calls at a
    // hundredth of a cent each is twelve cents, not zero.
    'fraction of a cent, many units' => ['0.00012', '1000', 'EUR', 12],
    'exact half rounds up' => ['0.005', '1', 'EUR', 1],
    'just below half rounds down' => ['0.004', '1', 'EUR', 0],
    'second exact half rounds up' => ['0.015', '1', 'EUR', 2],
    'whole units' => ['1.50', '4', 'EUR', 600],
    'zero quantity costs nothing' => ['9.99', '0', 'EUR', 0],
    'zero price costs nothing' => ['0', '1000000', 'EUR', 0],
    'fractional quantity' => ['0.25', '2.5', 'EUR', 63],
    'long multiplication rounds once at the end' => ['12.34567891', '3', 'EUR', 3704],
    'currency without a minor unit' => ['0.5', '1', 'JPY', 1],
    'currency with three decimals' => ['1.0005', '1', 'BHD', 1001],
]);

it('rounds the product, never the operands', function (): void {
    // Rounding each factor first would give 0.00 x 3 = 0. Rounding once at the
    // end gives a cent, which is what the customer is actually charged.
    $total = UnitPrice::fromString('0.004', 'EUR')->multipliedBy(Quantity::fromString('3'));

    expect($total->minorUnits())->toBe(1);
});

it('states the scale its column has to hold', function (): void {
    // The migration that creates numeric(20, 8) reads this constant. If the two
    // ever disagree, a price would be accepted and then silently truncated.
    expect(UnitPrice::SCALE)->toBe(8);
});

it('keeps the price itself untouched by a multiplication', function (): void {
    $price = UnitPrice::fromString('0.00012', 'EUR');
    $price->multipliedBy(Quantity::fromString('1000'));

    expect((string) $price->toBigDecimal())->toBe('0.00012000');
});
