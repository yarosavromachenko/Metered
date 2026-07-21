<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Metered\Billing\Domain\Plan\Plan;
use Metered\Billing\Domain\Plan\PlanCode;
use Metered\Billing\Domain\Plan\PlanRepository;
use Metered\Billing\Domain\Plan\PlanVersion;
use Metered\Billing\Domain\Plan\PlanVersionRepository;
use Metered\Billing\Domain\Plan\Price;
use Metered\Billing\Domain\Pricing\FlatFee;
use Metered\Billing\Domain\Pricing\Graduated;
use Metered\Billing\Domain\Pricing\PerUnit;
use Metered\Billing\Domain\Pricing\Tier;
use Metered\Billing\Domain\Pricing\Tiers;
use Metered\Billing\Domain\Pricing\Volume;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Money\UnitPrice;
use Metered\Shared\Domain\Quantity\Quantity;
use Metered\Shared\Domain\Tenant\TenantContext;
use Tests\Support\CatalogFactory;
use Tests\Support\TenantFactory;

function newId(): Uuid
{
    return app(IdentifierGenerator::class)->generate();
}

function exampleTiers(): Tiers
{
    return Tiers::of([
        Tier::upTo(Quantity::fromString('1000'), UnitPrice::fromString('0.10', 'EUR')),
        Tier::upTo(Quantity::fromString('5000.5'), UnitPrice::fromString('0.08', 'EUR')),
        Tier::unbounded(UnitPrice::fromString('0.00012345', 'EUR')),
    ]);
}

it('stores a plan and finds it by code, inside its own project only', function (): void {
    $acme = TenantFactory::tenant('acme')->tenant();
    $rival = TenantFactory::tenant('north-wind')->tenant();
    $plan = CatalogFactory::plan($acme, 'pro', 'Pro');

    $plans = app(PlanRepository::class);

    expect($plans->findByCode($acme, PlanCode::fromString('PRO'))?->id->value)->toBe($plan->id->value)
        ->and($plans->find($acme, $plan->id)?->name)->toBe('Pro')
        ->and($plans->find($rival, $plan->id))->toBeNull()
        ->and($plans->findByCode($rival, PlanCode::fromString('pro')))->toBeNull()
        ->and($plans->find(new TenantContext($rival->organizationId, $acme->projectId), $plan->id))->toBeNull()
        ->and(array_map(static fn(Plan $p): string => (string) $p->code, $plans->listFor($acme)))->toBe(['pro'])
        ->and($plans->listFor($rival))->toBe([]);
});

it('refuses a second plan with the same code in one project', function (): void {
    $tenant = TenantFactory::tenant()->tenant();
    CatalogFactory::plan($tenant, 'pro');

    expect(static fn(): Plan => CatalogFactory::plan($tenant, 'pro'))->toThrow(QueryException::class);
});

it('reads back every pricing model exactly as it was saved', function (): void {
    $tenant = TenantFactory::tenant()->tenant();
    $requests = CatalogFactory::meter($tenant, 'api.requests');
    $storage = CatalogFactory::meter($tenant, 'storage.gb');
    $seats = CatalogFactory::meter($tenant, 'seats');

    $version = CatalogFactory::version(CatalogFactory::plan($tenant), [
        Price::fixed(newId(), FlatFee::of(Money::ofMinorUnits(4900, 'EUR'))),
        Price::metered(newId(), PerUnit::at(UnitPrice::fromString('0.00012', 'EUR')), $requests->id),
        Price::metered(newId(), Graduated::over(exampleTiers()), $storage->id),
        Price::metered(newId(), Volume::over(exampleTiers()), $seats->id),
    ]);

    $stored = app(PlanVersionRepository::class)->find($tenant, $version->id);

    expect($stored)->not->toBeNull()
        ->and($stored?->isPublished())->toBeTrue()
        ->and($stored?->publishedAt?->format('U.u'))->toBe($version->publishedAt?->format('U.u'))
        ->and(array_map(static fn(Price $p): ?string => $p->meterId?->value, $stored->prices ?? []))
        ->toBe([null, $requests->id->value, $storage->id->value, $seats->id->value]);

    // Same charges for the same usage is what "exactly as saved" means for
    // money: prices, limits and the tier order all survive the round trip.
    foreach (['0', '1000', '5000.5', '5001', '123456.789'] as $quantity) {
        foreach ($version->prices as $index => $price) {
            expect($stored?->prices[$index]->charge(Quantity::fromString($quantity))->minorUnits())
                ->toBe($price->charge(Quantity::fromString($quantity))->minorUnits());
        }
    }
});

it('numbers versions per plan, newest first', function (): void {
    $tenant = TenantFactory::tenant()->tenant();
    $pro = CatalogFactory::plan($tenant, 'pro');
    $team = CatalogFactory::plan($tenant, 'team');

    CatalogFactory::version($pro);
    CatalogFactory::version($pro);
    CatalogFactory::version($team);

    $versions = app(PlanVersionRepository::class);

    expect(array_map(static fn(PlanVersion $v): int => $v->number, $versions->listForPlan($tenant, $pro->id)))->toBe([2, 1])
        ->and($versions->nextNumber($tenant, $pro->id))->toBe(3)
        ->and($versions->nextNumber($tenant, $team->id))->toBe(2);
});

it('saves a draft again with the prices it now holds', function (): void {
    $tenant = TenantFactory::tenant()->tenant();
    $meter = CatalogFactory::meter($tenant);
    $draft = CatalogFactory::version(CatalogFactory::plan($tenant), published: false);
    $versions = app(PlanVersionRepository::class);

    $fee = $draft->prices[0];
    $changed = $draft->withoutPrice($fee->id)
        ->withPrice(Price::metered(newId(), PerUnit::at(UnitPrice::fromString('0.5', 'EUR')), $meter->id));
    $versions->save($changed);

    expect(array_map(static fn(Price $p): ?string => $p->meterId?->value, $versions->find($tenant, $draft->id)->prices ?? []))
        ->toBe([$meter->id->value]);

    $versions->save($changed->publish(new DateTimeImmutable('2026-09-23T12:00:00+00:00')));

    expect($versions->find($tenant, $draft->id)?->isPublished())->toBeTrue();
});

it('refuses, in the database, any change to a published version or its prices', function (string $statement): void {
    $tenant = TenantFactory::tenant()->tenant();
    $version = CatalogFactory::version(CatalogFactory::plan($tenant));

    expect(static fn() => DB::statement(str_replace(':id', $version->id->value, $statement)))
        ->toThrow(QueryException::class, 'is published');
})->with([
    'the version' => ["UPDATE plan_versions SET currency = 'USD' WHERE id = ':id'"],
    'a price' => ["UPDATE prices SET flat_amount = 1 WHERE plan_version_id = ':id'"],
    'removing a price' => ["DELETE FROM prices WHERE plan_version_id = ':id'"],
    'adding a price' => ["INSERT INTO prices (id, organization_id, project_id, plan_version_id, position, model, currency, flat_amount)
        SELECT gen_random_uuid(), organization_id, project_id, id, 9, 'flat_fee', 'EUR', 100 FROM plan_versions WHERE id = ':id'"],
]);

it('lets an unused version go with its project', function (): void {
    $project = TenantFactory::tenant();
    $version = CatalogFactory::version(CatalogFactory::plan($project->tenant()));

    DB::table('projects')->where('id', $project->tenant()->projectId->value)->delete();

    expect(DB::table('plan_versions')->where('id', $version->id->value)->exists())->toBeFalse()
        ->and(DB::table('prices')->where('plan_version_id', $version->id->value)->exists())->toBeFalse();
});

it('refuses a price on another project\'s meter, whatever writes the row', function (): void {
    $acme = TenantFactory::tenant('acme')->tenant();
    $theirMeter = CatalogFactory::meter(TenantFactory::tenant('north-wind')->tenant());

    expect(static fn(): PlanVersion => CatalogFactory::version(CatalogFactory::plan($acme), [
        Price::metered(newId(), PerUnit::at(UnitPrice::fromString('0.1', 'EUR')), $theirMeter->id),
    ]))->toThrow(QueryException::class, 'prices_meter_id_project_id_foreign');
});
