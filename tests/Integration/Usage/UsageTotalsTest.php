<?php

declare(strict_types=1);

use Metered\Shared\Domain\Metering\Aggregation;
use Metered\Shared\Domain\Quantity\Quantity;
use Metered\Usage\Application\Contract\UsageTotals;
use Tests\Support\CatalogFactory;
use Tests\Support\TenantFactory;
use Tests\Support\UsageFactory;

/**
 * @param array<string, Quantity> $totals
 *
 * @return array<string, string>
 */
function totalsOf(array $totals): array
{
    return array_map(static fn(Quantity $quantity): string => (string) $quantity, $totals);
}

it('totals each meter over a period, adding sums and taking the peak of a max', function (): void {
    $tenant = TenantFactory::tenant()->tenant();
    $requests = CatalogFactory::meter($tenant, 'api.requests');
    $seats = CatalogFactory::meter($tenant, 'seats.peak', Aggregation::Max);
    $customer = CatalogFactory::customer($tenant, 'cus_4471');

    UsageFactory::aggregate($customer, $requests, '2026-02-01T00:00:00Z', '10.5');
    UsageFactory::aggregate($customer, $requests, '2026-02-14T13:00:00Z', '4');
    UsageFactory::aggregate($customer, $seats, '2026-02-02T00:00:00Z', '7');
    UsageFactory::aggregate($customer, $seats, '2026-02-03T00:00:00Z', '12');
    UsageFactory::aggregate($customer, $seats, '2026-02-04T00:00:00Z', '9');

    $totals = app(UsageTotals::class)->forPeriod(
        $tenant,
        $customer->id,
        new DateTimeImmutable('2026-02-01T00:00:00Z'),
        new DateTimeImmutable('2026-03-01T00:00:00Z'),
    );

    expect(totalsOf($totals))->toEqualCanonicalizing([
        $requests->id->value => '14.500000',
        $seats->id->value => '12.000000',
    ]);
});

it('counts a bucket toward the period its start falls in, whatever instant the period starts at', function (): void {
    $tenant = TenantFactory::tenant()->tenant();
    $meter = CatalogFactory::meter($tenant, 'api.requests');
    $customer = CatalogFactory::customer($tenant, 'cus_4471');

    // A subscription anchored at 10:30:00.25 splits the 10:00 and 11:00
    // buckets between its periods by their starts, never by their contents.
    UsageFactory::aggregate($customer, $meter, '2026-01-31T10:00:00Z', '1');
    UsageFactory::aggregate($customer, $meter, '2026-01-31T11:00:00Z', '2');
    UsageFactory::aggregate($customer, $meter, '2026-02-28T10:00:00Z', '4');
    UsageFactory::aggregate($customer, $meter, '2026-02-28T11:00:00Z', '8');

    $totals = app(UsageTotals::class);
    $first = $totals->forPeriod($tenant, $customer->id, new DateTimeImmutable('2026-01-31T10:30:00.25Z'), new DateTimeImmutable('2026-02-28T10:30:00.25Z'));
    $second = $totals->forPeriod($tenant, $customer->id, new DateTimeImmutable('2026-02-28T10:30:00.25Z'), new DateTimeImmutable('2026-03-31T10:30:00.25Z'));

    expect(totalsOf($first))->toBe([$meter->id->value => '6.000000'])
        ->and(totalsOf($second))->toBe([$meter->id->value => '8.000000']);
});

it('reads only this customer in this project', function (): void {
    $acme = TenantFactory::tenant('acme')->tenant();
    $rival = TenantFactory::tenant('north-wind')->tenant();
    $meter = CatalogFactory::meter($acme, 'api.requests');
    $customer = CatalogFactory::customer($acme, 'cus_4471');
    $other = CatalogFactory::customer($acme, 'cus_9000');

    UsageFactory::aggregate($other, $meter, '2026-02-01T00:00:00Z', '10');

    $from = new DateTimeImmutable('2026-02-01T00:00:00Z');
    $to = new DateTimeImmutable('2026-03-01T00:00:00Z');

    expect(app(UsageTotals::class)->forPeriod($acme, $customer->id, $from, $to))->toBe([])
        ->and(app(UsageTotals::class)->forPeriod($rival, $other->id, $from, $to))->toBe([]);
});
