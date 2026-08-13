<?php

declare(strict_types=1);

use Metered\Billing\Application\Contract\BillablePeriod;
use Metered\Billing\Application\Contract\BillableSubscription;
use Metered\Billing\Application\Contract\Charge;
use Metered\Billing\Application\Contract\SubscriptionBilling;
use Metered\Billing\Domain\Meter;
use Metered\Billing\Domain\Plan\Price;
use Metered\Billing\Domain\Pricing\FlatFee;
use Metered\Billing\Domain\Pricing\PerUnit;
use Metered\Billing\Domain\Subscription\Subscription;
use Metered\Billing\Domain\Subscription\SubscriptionRepository;
use Metered\Billing\Domain\Subscription\SubscriptionStatus;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Money\UnitPrice;
use Metered\Shared\Domain\Quantity\Quantity;
use Metered\Shared\Domain\Tenant\TenantContext;
use Tests\Support\CatalogFactory;
use Tests\Support\TenantFactory;

/**
 * A subscription anchored on 31 January on a version with a 29.00 flat fee and
 * requests at 0.01 each.
 *
 * @return array{tenant: TenantContext, subscription: Subscription, meter: Meter, fee: Price, perRequest: Price}
 */
function billedSubscription(string $slug = 'acme'): array
{
    $tenant = TenantFactory::tenant($slug)->tenant();
    $meter = CatalogFactory::meter($tenant, 'api.requests');
    $fee = Price::fixed(app(IdentifierGenerator::class)->generate(), FlatFee::of(Money::ofMinorUnits(2900, 'EUR')));
    $perRequest = Price::metered(app(IdentifierGenerator::class)->generate(), PerUnit::at(UnitPrice::fromString('0.01', 'EUR')), $meter->id);
    $version = CatalogFactory::version(CatalogFactory::plan($tenant), [$fee, $perRequest]);
    $customer = CatalogFactory::customer($tenant, 'cus_4471');

    return [
        'tenant' => $tenant,
        'subscription' => CatalogFactory::subscription($customer, $version, new DateTimeImmutable('2026-01-31T14:00:00Z')),
        'meter' => $meter,
        'fee' => $fee,
        'perRequest' => $perRequest,
    ];
}

it('lists the subscriptions that may have a period to invoice, in every tenant', function (): void {
    ['subscription' => $acme] = billedSubscription('acme');
    ['subscription' => $rival] = billedSubscription('north-wind');

    $ids = array_map(static fn(BillableSubscription $s): string => $s->id->value, app(SubscriptionBilling::class)->billable(new DateTimeImmutable('2026-01-01T00:00:00Z')));

    expect($ids)->toEqualCanonicalizing([$acme->id->value, $rival->id->value]);
});

it('stops listing a subscription once it ended before the cutoff', function (): void {
    ['subscription' => $subscription] = billedSubscription();
    app(SubscriptionRepository::class)->save($subscription->cancelNow(new DateTimeImmutable('2026-03-10T00:00:00Z')));

    $billing = app(SubscriptionBilling::class);

    expect($billing->billable(new DateTimeImmutable('2026-03-09T23:59:59Z')))->toHaveCount(1)
        ->and($billing->billable(new DateTimeImmutable('2026-03-10T00:00:00Z')))->toBe([]);
});

it('describes a subscription for its own tenant only', function (): void {
    ['tenant' => $tenant, 'subscription' => $subscription] = billedSubscription();
    $rival = TenantFactory::tenant('north-wind')->tenant();

    $found = app(SubscriptionBilling::class)->find($tenant, $subscription->id);

    expect($found?->customerId->value)->toBe($subscription->customerId->value)
        ->and($found?->currency)->toBe('EUR')
        ->and($found?->tenant->equals($tenant))->toBeTrue()
        ->and($found?->anchorAt->format(DATE_ATOM))->toBe('2026-01-31T14:00:00+00:00')
        ->and(app(SubscriptionBilling::class)->find($rival, $subscription->id))->toBeNull()
        ->and(app(SubscriptionBilling::class)->periodsEndedBy($rival, $subscription->id, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2027-01-01')))->toBe([]);
});

it('lists the ended periods from where invoicing stopped', function (): void {
    ['tenant' => $tenant, 'subscription' => $subscription] = billedSubscription();

    $periods = app(SubscriptionBilling::class)->periodsEndedBy(
        $tenant,
        $subscription->id,
        new DateTimeImmutable('2026-02-28T14:00:00Z'),
        new DateTimeImmutable('2026-05-01T00:00:00Z'),
    );

    expect(array_map(static fn(BillablePeriod $p): string => $p->start->format('m-d') . '→' . $p->end->format('m-d'), $periods))
        ->toBe(['02-28→03-31', '03-31→04-30']);
});

it('prices a period on its version, fixed fee and usage, with the working', function (): void {
    ['tenant' => $tenant, 'subscription' => $subscription, 'meter' => $meter, 'fee' => $fee, 'perRequest' => $perRequest] = billedSubscription();

    $charges = app(SubscriptionBilling::class)->charges($tenant, $subscription->id, new DateTimeImmutable('2026-02-28T14:00:00Z'), [
        $meter->id->value => Quantity::fromString('1250'),
    ]);

    expect(array_map(static fn(Charge $c): array => [
        $c->priceId->value,
        $c->meterCode,
        $c->quantity instanceof Quantity ? (string) $c->quantity : null,
        (string) $c->amount,
        $c->calculation,
    ], $charges))->toBe([
        [$fee->id->value, null, null, '29.00 EUR', ['29.00 EUR per period, whatever was used']],
        [$perRequest->id->value, 'api.requests', '1250.000000', '12.50 EUR', ['1250 × 0.01 EUR = 12.50 EUR']],
    ])
        ->and($charges[1]->meterId?->value)->toBe($meter->id->value)
        ->and($charges[0]->meterId)->toBeNull();
});

it('prices a meter nobody used at nothing', function (): void {
    ['tenant' => $tenant, 'subscription' => $subscription] = billedSubscription();

    $charges = app(SubscriptionBilling::class)->charges($tenant, $subscription->id, new DateTimeImmutable('2026-01-31T14:00:00Z'), []);

    expect((string) $charges[1]->quantity)->toBe('0.000000')
        ->and((string) $charges[1]->amount)->toBe('0.00 EUR');
});

it('refuses to price an instant outside the subscription', function (): void {
    ['tenant' => $tenant, 'subscription' => $subscription] = billedSubscription();

    app(SubscriptionBilling::class)->charges($tenant, $subscription->id, new DateTimeImmutable('2026-01-01T00:00:00Z'), []);
})->throws(RuntimeException::class, 'has no plan version at');

it('lapses a pending cancellation once its end has passed, and nothing else', function (): void {
    ['tenant' => $tenant, 'subscription' => $subscription] = billedSubscription();
    $repository = app(SubscriptionRepository::class);
    $repository->save($subscription->cancelAtPeriodEnd(new DateTimeImmutable('2026-02-10T00:00:00Z')));
    $billing = app(SubscriptionBilling::class);

    $billing->lapse($tenant, $subscription->id, new DateTimeImmutable('2026-02-28T13:59:59Z'));
    $before = $repository->find($tenant, $subscription->id)?->status;

    $billing->lapse($tenant, $subscription->id, new DateTimeImmutable('2026-02-28T14:00:00Z'));
    $billing->lapse($tenant, Uuid::fromString('01924b7c-0000-7000-8000-00000000f0ff'), new DateTimeImmutable('2026-03-01T00:00:00Z'));

    expect($before)->toBe(SubscriptionStatus::PendingCancellation)
        ->and($repository->find($tenant, $subscription->id)?->status)->toBe(SubscriptionStatus::Canceled);
});
