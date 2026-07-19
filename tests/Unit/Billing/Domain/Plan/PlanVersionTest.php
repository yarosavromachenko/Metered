<?php

declare(strict_types=1);

use Metered\Billing\Domain\Exception\InvalidPlanVersion;
use Metered\Billing\Domain\Exception\PlanVersionLocked;
use Metered\Billing\Domain\Period\BillingInterval;
use Metered\Billing\Domain\Plan\PlanVersion;
use Metered\Billing\Domain\Plan\Price;
use Metered\Billing\Domain\Pricing\FlatFee;
use Metered\Billing\Domain\Pricing\PerUnit;
use Metered\Shared\Domain\Exception\InvalidMoney;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Money\UnitPrice;
use Metered\Shared\Domain\Tenant\TenantContext;

function draftVersion(int $number = 1, string $currency = 'EUR'): PlanVersion
{
    return PlanVersion::draft(
        Uuid::fromString('01924b7c-0000-7000-8000-000000000a01'),
        new TenantContext(
            Uuid::fromString('01924b7c-0000-7000-8000-000000000a02'),
            Uuid::fromString('01924b7c-0000-7000-8000-000000000a03'),
        ),
        Uuid::fromString('01924b7c-0000-7000-8000-000000000a04'),
        $number,
        $currency,
        BillingInterval::Month,
        new DateTimeImmutable('2026-09-23T10:00:00+00:00'),
    );
}

function baseFee(string $id = '01924b7c-0000-7000-8000-000000000b01', string $currency = 'EUR'): Price
{
    return Price::fixed(Uuid::fromString($id), FlatFee::of(Money::ofMinorUnits(4900, $currency)));
}

function perRequest(string $id = '01924b7c-0000-7000-8000-000000000b02', string $meter = '01924b7c-0000-7000-8000-000000000c01'): Price
{
    return Price::metered(Uuid::fromString($id), PerUnit::at(UnitPrice::fromString('0.002', 'EUR')), Uuid::fromString($meter));
}

it('starts as a draft of one plan, in one currency, billed on one interval', function (): void {
    $version = draftVersion(currency: ' eur ');

    expect($version->number)->toBe(1)
        ->and($version->planId->value)->toBe('01924b7c-0000-7000-8000-000000000a04')
        ->and($version->tenant->projectId->value)->toBe('01924b7c-0000-7000-8000-000000000a03')
        ->and($version->currency)->toBe('EUR')
        ->and($version->interval)->toBe(BillingInterval::Month)
        ->and($version->createdAt->format(DATE_ATOM))->toBe('2026-09-23T10:00:00+00:00')
        ->and($version->prices)->toBe([])
        ->and($version->isPublished())->toBeFalse()
        ->and($version->publishedAt)->toBeNull();
});

it('refuses a number below one and a currency that does not exist', function (): void {
    expect(static fn(): PlanVersion => draftVersion(number: 0))
        ->toThrow(InvalidPlanVersion::class, 'numbered from 1, 0 given')
        ->and(static fn(): PlanVersion => draftVersion(currency: 'ZZZ'))
        ->toThrow(InvalidMoney::class);
});

it('collects prices while it is a draft, without changing the version it was', function (): void {
    $empty = draftVersion();
    $priced = $empty->withPrice(baseFee())->withPrice(perRequest());

    expect($empty->prices)->toBe([])
        ->and(array_map(static fn(Price $p): string => $p->id->value, $priced->prices))
        ->toBe(['01924b7c-0000-7000-8000-000000000b01', '01924b7c-0000-7000-8000-000000000b02']);
});

it('removes a price by its id, and only that one', function (): void {
    $version = draftVersion()->withPrice(baseFee())->withPrice(perRequest())
        ->withoutPrice(Uuid::fromString('01924b7c-0000-7000-8000-000000000b01'));

    expect(array_map(static fn(Price $p): string => $p->id->value, $version->prices))
        ->toBe(['01924b7c-0000-7000-8000-000000000b02']);
});

it('refuses a price that would make the version bill wrongly', function (Closure $change, string $message): void {
    /** @var Closure(PlanVersion): PlanVersion $change */
    expect(static fn(): PlanVersion => $change(draftVersion()->withPrice(baseFee())->withPrice(perRequest())))
        ->toThrow(InvalidPlanVersion::class, $message);
})->with([
    'another currency' => [
        static fn(PlanVersion $v): PlanVersion => $v->withPrice(baseFee('01924b7c-0000-7000-8000-000000000b09', 'USD')),
        'is priced in EUR; a USD price',
    ],
    'the same meter twice' => [
        static fn(PlanVersion $v): PlanVersion => $v->withPrice(perRequest('01924b7c-0000-7000-8000-000000000b09')),
        'already has a price on meter 01924b7c-0000-7000-8000-000000000c01',
    ],
    'the same price twice' => [
        static fn(PlanVersion $v): PlanVersion => $v->withPrice(baseFee()),
        'already holds price 01924b7c-0000-7000-8000-000000000b01',
    ],
    'removing a price it does not hold' => [
        static fn(PlanVersion $v): PlanVersion => $v->withoutPrice(Uuid::fromString('01924b7c-0000-7000-8000-000000000b09')),
        'holds no price 01924b7c-0000-7000-8000-000000000b09',
    ],
]);

it('allows two usage prices on different meters', function (): void {
    $version = draftVersion()
        ->withPrice(perRequest())
        ->withPrice(perRequest('01924b7c-0000-7000-8000-000000000b03', '01924b7c-0000-7000-8000-000000000c02'));

    expect($version->prices)->toHaveCount(2);
});

it('is published once, and only with something to charge', function (): void {
    $published = draftVersion()->withPrice(baseFee())->publish(new DateTimeImmutable('2026-09-24T08:00:00+00:00'));

    expect($published->isPublished())->toBeTrue()
        ->and($published->publishedAt?->format(DATE_ATOM))->toBe('2026-09-24T08:00:00+00:00')
        ->and(static fn(): PlanVersion => draftVersion()->publish(new DateTimeImmutable()))
        ->toThrow(InvalidPlanVersion::class, 'without a single price');
});

it('cannot change once published, because a subscription may already be billed on it', function (Closure $change): void {
    /** @var Closure(PlanVersion): PlanVersion $change */
    $published = draftVersion()->withPrice(baseFee())->publish(new DateTimeImmutable('2026-09-24T08:00:00+00:00'));

    expect(static fn(): PlanVersion => $change($published))
        ->toThrow(PlanVersionLocked::class, 'Version 1 is published');
})->with([
    'add a price' => [static fn(PlanVersion $v): PlanVersion => $v->withPrice(perRequest())],
    'remove a price' => [static fn(PlanVersion $v): PlanVersion => $v->withoutPrice(Uuid::fromString('01924b7c-0000-7000-8000-000000000b01'))],
    'publish again' => [static fn(PlanVersion $v): PlanVersion => $v->publish(new DateTimeImmutable())],
]);
