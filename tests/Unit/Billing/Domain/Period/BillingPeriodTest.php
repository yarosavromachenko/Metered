<?php

declare(strict_types=1);

use Metered\Billing\Domain\Exception\InvalidPeriod;
use Metered\Billing\Domain\Period\BillingPeriod;

function instant(string $at): DateTimeImmutable
{
    return new DateTimeImmutable($at, new DateTimeZone('UTC'));
}

it('is half-open: it holds its start and not its end', function (): void {
    $period = BillingPeriod::between(instant('2026-03-01'), instant('2026-04-01'));

    expect($period->contains(instant('2026-03-01')))->toBeTrue()
        ->and($period->contains(instant('2026-03-31 23:59:59.999999')))->toBeTrue()
        ->and($period->contains(instant('2026-04-01')))->toBeFalse()
        ->and($period->contains(instant('2026-02-28 23:59:59.999999')))->toBeFalse();
});

it('refuses a period that ends where it starts, or before', function (string $end): void {
    expect(static fn(): BillingPeriod => BillingPeriod::between(instant('2026-03-01'), instant($end)))
        ->toThrow(InvalidPeriod::class, 'must end after it starts');
})->with(['empty' => ['2026-03-01'], 'backwards' => ['2026-02-01']]);

it('stores both edges in UTC whatever zone they came in', function (): void {
    $period = BillingPeriod::between(
        new DateTimeImmutable('2026-03-01 01:00:00', new DateTimeZone('Europe/Berlin')),
        instant('2026-04-01'),
    );

    expect((string) $period)->toBe('[2026-03-01T00:00:00Z, 2026-04-01T00:00:00Z)')
        ->and($period->start->getTimezone()->getName())->toBe('UTC')
        ->and($period->end->getTimezone()->getName())->toBe('UTC');
});

it('is equal to another period with the same instants', function (): void {
    $period = BillingPeriod::between(instant('2026-03-01'), instant('2026-04-01'));

    expect($period->equals(BillingPeriod::between(instant('2026-03-01'), instant('2026-04-01'))))->toBeTrue()
        ->and($period->equals(BillingPeriod::between(instant('2026-03-01'), instant('2026-04-02'))))->toBeFalse()
        ->and($period->equals(BillingPeriod::between(instant('2026-02-28'), instant('2026-04-01'))))->toBeFalse();
});
