<?php

declare(strict_types=1);

use Metered\Billing\Domain\Exception\InvalidPricing;
use Metered\Billing\Domain\Pricing\Tier;
use Metered\Billing\Domain\Pricing\Tiers;
use Metered\Shared\Domain\Money\UnitPrice;
use Metered\Shared\Domain\Quantity\Quantity;

function tierUpTo(string $limit, string $price = '0.10', string $currency = 'EUR'): Tier
{
    return Tier::upTo(Quantity::fromString($limit), UnitPrice::fromString($price, $currency));
}

function tierBeyond(string $price = '0.05', string $currency = 'EUR'): Tier
{
    return Tier::unbounded(UnitPrice::fromString($price, $currency));
}

it('keeps the tiers in the order they were given', function (): void {
    $tiers = Tiers::of([tierUpTo('10'), tierUpTo('20', '0.08'), tierBeyond()]);

    expect($tiers->all())->toHaveCount(3)
        ->and((string) $tiers->all()[1]->limit)->toBe('20.000000')
        ->and($tiers->all()[2]->limit)->toBeNull()
        ->and($tiers->currency())->toBe('EUR');
});

it('refuses a tier table that cannot price every quantity', function (array $tiers, string $message): void {
    /** @var list<Tier> $tiers */
    expect(static fn(): Tiers => Tiers::of($tiers))->toThrow(InvalidPricing::class, $message);
})->with([
    'no tiers at all' => [[], 'at least one tier'],
    'the last tier has a limit' => [[tierUpTo('10'), tierUpTo('20')], 'last tier must be unbounded'],
    'an unbounded tier before the last' => [[tierBeyond(), tierUpTo('20'), tierBeyond()], 'Only the last tier may be unbounded; tier 1 is.'],
    'a first limit of zero' => [[tierUpTo('0'), tierBeyond()], 'tier 1 ends at or below'],
    'a limit equal to the one before' => [[tierUpTo('10'), tierUpTo('10'), tierBeyond()], 'tier 2 ends at or below'],
    'a limit below the one before' => [[tierUpTo('10'), tierUpTo('5'), tierBeyond()], 'tier 2 ends at or below'],
    'a later unbounded tier' => [[tierUpTo('10'), tierBeyond(), tierBeyond()], 'tier 2 is.'],
    'two currencies' => [[tierUpTo('10'), tierBeyond('0.05', 'USD')], 'one currency'],
]);
