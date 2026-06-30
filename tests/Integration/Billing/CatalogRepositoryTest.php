<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Metered\Billing\Domain\CustomerReference;
use Metered\Billing\Domain\CustomerRepository;
use Metered\Billing\Domain\Meter;
use Metered\Billing\Domain\MeterCode;
use Metered\Billing\Domain\MeterRepository;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Metering\Aggregation;
use Metered\Shared\Domain\Tenant\TenantContext;
use Psr\Clock\ClockInterface;
use Tests\Support\CatalogFactory;
use Tests\Support\TenantFactory;

it('stores a meter and finds it by the code events will carry', function (): void {
    $tenant = TenantFactory::tenant()->tenant();
    $meter = CatalogFactory::meter($tenant, 'api.requests', Aggregation::Count);

    $found = app(MeterRepository::class)->findByCode($tenant, MeterCode::fromString('API.Requests'));

    expect($found?->id->value)->toBe($meter->id->value)
        ->and($found?->aggregation)->toBe(Aggregation::Count)
        ->and($found?->tenant->projectId->value)->toBe($tenant->projectId->value);
});

it('will not hand a meter to the wrong tenant', function (): void {
    $acme = TenantFactory::tenant('acme')->tenant();
    $rival = TenantFactory::tenant('north-wind')->tenant();
    $meter = CatalogFactory::meter($acme, 'api.requests');

    $meters = app(MeterRepository::class);

    expect($meters->find($acme, $meter->id)?->id->value)->toBe($meter->id->value)
        ->and($meters->find($rival, $meter->id))->toBeNull()
        ->and($meters->findByCode($rival, MeterCode::fromString('api.requests')))->toBeNull()
        // Their organization paired with Acme's project: two halves that do
        // not belong together.
        ->and($meters->find(new TenantContext($rival->organizationId, $acme->projectId), $meter->id))
        ->toBeNull();
});

it('lets two projects define the same code independently', function (): void {
    $acme = TenantFactory::tenant('acme')->tenant();
    $rival = TenantFactory::tenant('north-wind')->tenant();

    $theirs = CatalogFactory::meter($acme, 'api.requests', Aggregation::Sum);
    $ours = CatalogFactory::meter($rival, 'api.requests', Aggregation::Count);

    expect($theirs->id->value)->not->toBe($ours->id->value)
        ->and(app(MeterRepository::class)->findByCode($rival, MeterCode::fromString('api.requests'))?->aggregation)
        ->toBe(Aggregation::Count);
});

it('refuses a second meter with the same code in one project', function (): void {
    $tenant = TenantFactory::tenant()->tenant();
    CatalogFactory::meter($tenant, 'api.requests');

    // The database says so, not a handler: a seeder, a console session or a
    // race between two panels must fail here too.
    expect(static fn(): Meter => CatalogFactory::meter($tenant, 'api.requests', Aggregation::Max))
        ->toThrow(QueryException::class);
});

it('refuses a meter whose organization is not the one its project belongs to', function (): void {
    $acme = TenantFactory::tenant('acme')->tenant();
    $rival = TenantFactory::tenant('north-wind')->tenant();

    $mismatched = new TenantContext($rival->organizationId, $acme->projectId);

    expect(static fn(): Meter => CatalogFactory::meter($mismatched))->toThrow(QueryException::class);
});

it('refuses an aggregation the domain does not offer', function (): void {
    $tenant = TenantFactory::tenant()->tenant();

    expect(static fn(): int => DB::table('meters')->insertGetId([
        'id' => app(IdentifierGenerator::class)->generate()->value,
        'organization_id' => $tenant->organizationId->value,
        'project_id' => $tenant->projectId->value,
        'code' => 'api.requests',
        'name' => 'Api requests',
        'aggregation' => 'last',
        'created_at' => app(ClockInterface::class)->now(),
    ]))->toThrow(QueryException::class, 'meters_aggregation_check');
});

it('refuses a meter code no client could send', function (): void {
    $tenant = TenantFactory::tenant()->tenant();

    expect(static fn(): int => DB::table('meters')->insertGetId([
        'id' => app(IdentifierGenerator::class)->generate()->value,
        'organization_id' => $tenant->organizationId->value,
        'project_id' => $tenant->projectId->value,
        'code' => 'API Requests',
        'name' => 'Api requests',
        'aggregation' => 'sum',
        'created_at' => app(ClockInterface::class)->now(),
    ]))->toThrow(QueryException::class, 'meters_code_check');
});

it('stores a customer under the reference their own system uses', function (): void {
    $tenant = TenantFactory::tenant()->tenant();
    $customer = CatalogFactory::customer($tenant, 'CUS_Abc', 'North Wind Ltd');

    $customers = app(CustomerRepository::class);

    expect($customers->findByReference($tenant, CustomerReference::fromString('CUS_Abc'))?->id->value)
        ->toBe($customer->id->value)
        // Case is theirs to decide: two references differing in case may be
        // two different customers over there.
        ->and($customers->findByReference($tenant, CustomerReference::fromString('cus_abc')))
        ->toBeNull();
});

it('lists a project’s catalog and nobody else’s', function (): void {
    $acme = TenantFactory::tenant('acme')->tenant();
    $rival = TenantFactory::tenant('north-wind')->tenant();

    CatalogFactory::meter($acme, 'api.requests');
    CatalogFactory::meter($acme, 'storage.gb');
    CatalogFactory::meter($rival, 'theirs.only');
    CatalogFactory::customer($acme, 'cus_1');
    CatalogFactory::customer($rival, 'cus_2');

    $meters = array_map(
        static fn(Meter $meter): string => $meter->code->value,
        app(MeterRepository::class)->listFor($acme),
    );
    $customers = app(CustomerRepository::class)->listFor($acme);

    expect($meters)->toBe(['api.requests', 'storage.gb'])
        ->and($customers)->toHaveCount(1)
        ->and((string) $customers[0]->reference)->toBe('cus_1');
});
