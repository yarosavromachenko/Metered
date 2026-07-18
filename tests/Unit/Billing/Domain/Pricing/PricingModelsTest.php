<?php

declare(strict_types=1);

use Metered\Billing\Domain\Exception\InvalidPricing;
use Metered\Billing\Domain\Pricing\FlatFee;
use Metered\Billing\Domain\Pricing\Graduated;
use Metered\Billing\Domain\Pricing\PerUnit;
use Metered\Billing\Domain\Pricing\PricingModel;
use Metered\Billing\Domain\Pricing\Tier;
use Metered\Billing\Domain\Pricing\Tiers;
use Metered\Billing\Domain\Pricing\Volume;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Money\UnitPrice;
use Metered\Shared\Domain\Quantity\Quantity;

/**
 * The tier table every tiered case below is priced against: the first thousand
 * units at 0.10, up to five thousand at 0.08, everything beyond at 0.05.
 */
function threeTiers(): Tiers
{
    return Tiers::of([
        Tier::upTo(Quantity::fromString('1000'), UnitPrice::fromString('0.10', 'EUR')),
        Tier::upTo(Quantity::fromString('5000'), UnitPrice::fromString('0.08', 'EUR')),
        Tier::unbounded(UnitPrice::fromString('0.05', 'EUR')),
    ]);
}

function chargeOf(PricingModel $model, string $quantity): int
{
    return $model->charge(Quantity::fromString($quantity))->minorUnits();
}

it('charges a flat fee whatever was used', function (string $quantity): void {
    $fee = FlatFee::of(Money::ofMinorUnits(4900, 'EUR'));

    expect(chargeOf($fee, $quantity))->toBe(4900)
        ->and($fee->isUsageBased())->toBeFalse()
        ->and($fee->currency())->toBe('EUR');
})->with(['nothing' => ['0'], 'one unit' => ['1'], 'a great deal' => ['1000000']]);

it('allows a free plan but not a flat fee below zero', function (): void {
    expect(chargeOf(FlatFee::of(Money::zero('EUR')), '10'))->toBe(0)
        ->and(static fn(): FlatFee => FlatFee::of(Money::ofMinorUnits(-1, 'EUR')))
        ->toThrow(InvalidPricing::class, 'cannot be negative');
});

it('charges per unit, rounding once at the line', function (string $price, string $quantity, int $expected): void {
    $model = PerUnit::at(UnitPrice::fromString($price, 'EUR'));

    expect(chargeOf($model, $quantity))->toBe($expected)
        ->and($model->isUsageBased())->toBeTrue()
        ->and($model->currency())->toBe('EUR');
})->with([
    'nothing used' => ['0.10', '0', 0],
    'whole units' => ['0.10', '1500', 15000],
    'fractional quantity' => ['0.25', '2.5', 63],
    'fraction of a cent, many units' => ['0.00012', '1000', 12],
]);

it('prices each graduated tier on only the units inside it', function (string $quantity, int $expected): void {
    expect(chargeOf(Graduated::over(threeTiers()), $quantity))->toBe($expected);
})->with([
    'nothing used' => ['0', 0],
    'one unit' => ['1', 10],
    'just inside the first tier' => ['999.5', 9995],
    'exactly on the first boundary' => ['1000', 10000],
    'a millionth past the first boundary' => ['1000.000001', 10000],
    'one unit into the second tier' => ['1001', 10008],
    'exactly on the second boundary' => ['5000', 42000],
    'one unit into the last tier' => ['5001', 42005],
    'deep into the last tier' => ['10000', 67000],
]);

it('prices every unit at the volume tier the total reached', function (string $quantity, int $expected): void {
    expect(chargeOf(Volume::over(threeTiers()), $quantity))->toBe($expected);
})->with([
    'nothing used' => ['0', 0],
    'one unit' => ['1', 10],
    'exactly on the first boundary' => ['1000', 10000],
    'one unit past it drops every unit to the second price' => ['1001', 8008],
    'exactly on the second boundary' => ['5000', 40000],
    'one unit into the last tier' => ['5001', 25005],
    'deep into the last tier' => ['10000', 50000],
]);

it('tells graduated and volume apart on the same boundary', function (): void {
    // A boundary is inclusive: 5,000 units is still the second tier. Under
    // graduated pricing that means the first thousand kept their price; under
    // volume pricing all five thousand are at 0.08. The two models agree only
    // while every unit sits in the first tier.
    expect(chargeOf(Graduated::over(threeTiers()), '5000'))->toBe(42000)
        ->and(chargeOf(Volume::over(threeTiers()), '5000'))->toBe(40000)
        ->and(chargeOf(Graduated::over(threeTiers()), '1000'))
        ->toBe(chargeOf(Volume::over(threeTiers()), '1000'));
});

it('rounds a graduated charge once, after every tier is summed', function (): void {
    // Half a cent in each of two tiers. Rounded per tier that is two cents;
    // rounded once, as the line is printed, it is one.
    $tiers = Tiers::of([
        Tier::upTo(Quantity::fromString('1'), UnitPrice::fromString('0.005', 'EUR')),
        Tier::unbounded(UnitPrice::fromString('0.005', 'EUR')),
    ]);

    expect(chargeOf(Graduated::over($tiers), '2'))->toBe(1);
});

it('prices a single unbounded tier the same as per-unit, under either model', function (string $quantity): void {
    $price = UnitPrice::fromString('0.0375', 'EUR');
    $tiers = Tiers::of([Tier::unbounded($price)]);

    expect(chargeOf(Graduated::over($tiers), $quantity))
        ->toBe(chargeOf(PerUnit::at($price), $quantity))
        ->and(chargeOf(Volume::over($tiers), $quantity))
        ->toBe(chargeOf(PerUnit::at($price), $quantity));
})->with(['0', '1', '333.333333', '1000000']);

it('charges tiered usage in the currency of its tiers', function (): void {
    $graduated = Graduated::over(threeTiers());
    $volume = Volume::over(threeTiers());

    expect($graduated->charge(Quantity::fromString('10'))->currency())->toBe('EUR')
        ->and($graduated->currency())->toBe('EUR')
        ->and($graduated->isUsageBased())->toBeTrue()
        ->and($volume->currency())->toBe('EUR')
        ->and($volume->isUsageBased())->toBeTrue();
});
