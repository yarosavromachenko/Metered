<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Metered\Billing\Domain\Subscription\Subscription;
use Metered\Billing\Domain\Subscription\SubscriptionPhase;
use Metered\Billing\Domain\Subscription\SubscriptionRepository;
use Metered\Billing\Domain\Subscription\SubscriptionStatus;
use Metered\Shared\Domain\Tenant\TenantContext;
use Tests\Support\CatalogFactory;
use Tests\Support\TenantFactory;

it('reads a subscription back to the microsecond its periods are measured from', function (): void {
    $tenant = TenantFactory::tenant()->tenant();
    $customer = CatalogFactory::customer($tenant);
    $starter = CatalogFactory::version(CatalogFactory::plan($tenant, 'starter'));
    $pro = CatalogFactory::version(CatalogFactory::plan($tenant, 'pro'));

    $subscription = CatalogFactory::subscription($customer, $starter, new DateTimeImmutable('2026-01-31T14:00:00.123456+00:00'))
        ->changePlan($pro, new DateTimeImmutable('2026-02-15T09:00:00+00:00'));
    $subscriptions = app(SubscriptionRepository::class);
    $subscriptions->save($subscription);

    $stored = $subscriptions->find($tenant, $subscription->id);

    expect($stored?->anchorAt->format('Y-m-d H:i:s.u'))->toBe('2026-01-31 14:00:00.123456')
        ->and($stored?->status)->toBe(SubscriptionStatus::Active)
        ->and($stored?->currency)->toBe('EUR')
        ->and($stored?->customerId->value)->toBe($customer->id->value)
        ->and(array_map(
            static fn(SubscriptionPhase $phase): string => $phase->planVersionId->value . ' ' . $phase->startsAt->format('Y-m-d H:i:s.u'),
            $stored->phases ?? [],
        ))->toBe([
            $starter->id->value . ' 2026-01-31 14:00:00.123456',
            $pro->id->value . ' 2026-02-28 14:00:00.123456',
        ])
        ->and((string) $stored?->periodAt(new DateTimeImmutable('2026-03-01T00:00:00+00:00')))
        ->toBe((string) $subscription->periodAt(new DateTimeImmutable('2026-03-01T00:00:00+00:00')));
});

it('stores a cancellation and the phases it cut short', function (): void {
    $tenant = TenantFactory::tenant()->tenant();
    $subscription = CatalogFactory::subscription(
        CatalogFactory::customer($tenant),
        CatalogFactory::version(CatalogFactory::plan($tenant)),
        new DateTimeImmutable('2026-01-31T14:00:00+00:00'),
    )->cancelAtPeriodEnd(new DateTimeImmutable('2026-02-10T00:00:00+00:00'));

    $subscriptions = app(SubscriptionRepository::class);
    $subscriptions->save($subscription);
    $stored = $subscriptions->find($tenant, $subscription->id);

    expect($stored?->status)->toBe(SubscriptionStatus::PendingCancellation)
        ->and($stored?->endsAt?->format(DATE_ATOM))->toBe('2026-02-28T14:00:00+00:00')
        ->and($stored?->phases[0]->endsAt?->format(DATE_ATOM))->toBe('2026-02-28T14:00:00+00:00');
});

it('lists a customer\'s subscriptions newest first, inside its own project only', function (): void {
    $acme = TenantFactory::tenant('acme')->tenant();
    $rival = TenantFactory::tenant('north-wind')->tenant();
    $customer = CatalogFactory::customer($acme);
    $version = CatalogFactory::version(CatalogFactory::plan($acme));

    $older = CatalogFactory::subscription($customer, $version, new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $newer = CatalogFactory::subscription($customer, $version, new DateTimeImmutable('2026-06-01T00:00:00+00:00'));

    $subscriptions = app(SubscriptionRepository::class);

    expect(array_map(static fn(Subscription $s): string => $s->id->value, $subscriptions->listForCustomer($acme, $customer->id)))
        ->toBe([$newer->id->value, $older->id->value])
        ->and($subscriptions->listForCustomer($rival, $customer->id))->toBe([])
        ->and($subscriptions->find($rival, $older->id))->toBeNull()
        ->and($subscriptions->find(new TenantContext($rival->organizationId, $acme->projectId), $older->id))->toBeNull();
});

it('refuses overlapping phases, in the database', function (): void {
    $tenant = TenantFactory::tenant()->tenant();
    $subscription = CatalogFactory::subscription(
        CatalogFactory::customer($tenant),
        CatalogFactory::version(CatalogFactory::plan($tenant)),
        new DateTimeImmutable('2026-01-31T14:00:00+00:00'),
    );

    expect(static fn() => DB::statement(
        "INSERT INTO subscription_phases (subscription_id, organization_id, project_id, plan_version_id, starts_at, ends_at)
         SELECT subscription_id, organization_id, project_id, plan_version_id, '2026-03-01 00:00:00+00', NULL
         FROM subscription_phases WHERE subscription_id = ?",
        [$subscription->id->value],
    ))->toThrow(QueryException::class, 'subscription_phases_no_overlap');
});

it('refuses a phase on a draft version, in the database', function (): void {
    $tenant = TenantFactory::tenant()->tenant();
    $plan = CatalogFactory::plan($tenant);
    $subscription = CatalogFactory::subscription(CatalogFactory::customer($tenant), CatalogFactory::version($plan));
    $draft = CatalogFactory::version($plan, published: false);

    expect(static fn() => DB::table('subscription_phases')->where('subscription_id', $subscription->id->value)
        ->update(['plan_version_id' => $draft->id->value]))
        ->toThrow(QueryException::class, 'is not published');
});

it('refuses a subscription for another project\'s customer, whatever writes the row', function (): void {
    $acme = TenantFactory::tenant('acme')->tenant();
    $theirCustomer = CatalogFactory::customer(TenantFactory::tenant('north-wind')->tenant());
    $subscription = CatalogFactory::subscription(CatalogFactory::customer($acme), CatalogFactory::version(CatalogFactory::plan($acme)));

    expect(static fn() => DB::table('subscriptions')->where('id', $subscription->id->value)
        ->update(['customer_id' => $theirCustomer->id->value]))
        ->toThrow(QueryException::class, 'subscriptions_customer_id_project_id_foreign');
});

it('keeps a version for as long as a subscription uses it', function (): void {
    $tenant = TenantFactory::tenant()->tenant();
    $version = CatalogFactory::version(CatalogFactory::plan($tenant));
    CatalogFactory::subscription(CatalogFactory::customer($tenant), $version);

    expect(static fn() => DB::table('plan_versions')->where('id', $version->id->value)->delete())
        ->toThrow(QueryException::class, 'subscription_phases_plan_version_id_project_id_foreign');
});
