<?php

declare(strict_types=1);

use Metered\Billing\Domain\Exception\InvalidPricing;
use Metered\Billing\Domain\Plan\Price;
use Metered\Billing\Domain\Pricing\FlatFee;
use Metered\Billing\Domain\Pricing\PerUnit;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Money\UnitPrice;
use Metered\Shared\Domain\Quantity\Quantity;

const PRICE_ID = '01924b7c-0000-7000-8000-0000000000e1';
const PRICE_METER_ID = '01924b7c-0000-7000-8000-0000000000e2';

it('reads usage from a meter when its model is usage-based', function (): void {
    $price = Price::metered(
        Uuid::fromString(PRICE_ID),
        PerUnit::at(UnitPrice::fromString('0.002', 'EUR')),
        Uuid::fromString(PRICE_METER_ID),
    );

    expect($price->id->value)->toBe(PRICE_ID)
        ->and($price->meterId?->value)->toBe(PRICE_METER_ID)
        ->and($price->currency())->toBe('EUR')
        ->and($price->charge(Quantity::fromString('1500'))->minorUnits())->toBe(300);
});

it('charges a fixed fee without a meter', function (): void {
    $price = Price::fixed(Uuid::fromString(PRICE_ID), FlatFee::of(Money::ofMinorUnits(4900, 'EUR')));

    expect($price->meterId)->toBeNull()
        ->and($price->charge(Quantity::zero())->minorUnits())->toBe(4900);
});

it('refuses a model that does not fit the way it was attached', function (): void {
    expect(static fn(): Price => Price::metered(
        Uuid::fromString(PRICE_ID),
        FlatFee::of(Money::ofMinorUnits(4900, 'EUR')),
        Uuid::fromString(PRICE_METER_ID),
    ))->toThrow(InvalidPricing::class, 'does not depend on usage')
        ->and(static fn(): Price => Price::fixed(
            Uuid::fromString(PRICE_ID),
            PerUnit::at(UnitPrice::fromString('0.002', 'EUR')),
        ))->toThrow(InvalidPricing::class, 'needs a meter');
});
