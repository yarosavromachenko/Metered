<?php

declare(strict_types=1);

use Metered\Billing\Application\Contract\CustomerDirectory;
use Metered\Billing\Application\Contract\MeterCatalog;
use Metered\Shared\Domain\Metering\Aggregation;
use Tests\Support\CatalogFactory;
use Tests\Support\TenantFactory;

it('resolves the code an event carries to the meter behind it', function (): void {
    $tenant = TenantFactory::tenant()->tenant();
    $meter = CatalogFactory::meter($tenant, 'storage.gb', Aggregation::Max);

    $descriptor = app(MeterCatalog::class)->find($tenant, 'Storage.GB');

    expect($descriptor?->id->value)->toBe($meter->id->value)
        ->and($descriptor?->code)->toBe('storage.gb')
        ->and($descriptor?->aggregation)->toBe(Aggregation::Max);
});

it('answers "no such meter" rather than throwing at whatever a client sent', function (string $code): void {
    $tenant = TenantFactory::tenant()->tenant();
    CatalogFactory::meter($tenant, 'api.requests');

    // A typo in a client's instrumentation arrives five hundred times in one
    // batch. It has to be a rejected event with a reason, not an exception
    // unwinding the other four hundred and ninety-nine.
    expect(app(MeterCatalog::class)->find($tenant, $code))->toBeNull();
})->with([
    'a meter that does not exist' => ['api.responses'],
    'a code with a space' => ['api requests'],
    'an empty code' => [''],
    'a code that is far too long' => ['a' . str_repeat('b', 200)],
    'something that is not a code at all' => ['{"meter":"api.requests"}'],
]);

it('does not resolve another tenant’s meter', function (): void {
    $acme = TenantFactory::tenant('acme')->tenant();
    $rival = TenantFactory::tenant('north-wind')->tenant();
    CatalogFactory::meter($acme, 'api.requests');

    expect(app(MeterCatalog::class)->find($rival, 'api.requests'))->toBeNull();
});

it('lists this project’s meter codes in order, and no other project’s', function (): void {
    $acme = TenantFactory::tenant('acme')->tenant();
    $rival = TenantFactory::tenant('north-wind')->tenant();
    CatalogFactory::meter($acme, 'storage.gb', Aggregation::Max);
    CatalogFactory::meter($acme, 'api.requests');
    CatalogFactory::meter($rival, 'emails.sent');

    expect(app(MeterCatalog::class)->codes($acme))->toBe(['api.requests', 'storage.gb']);
});

it('resolves the reference an event carries to the customer behind it', function (): void {
    $tenant = TenantFactory::tenant()->tenant();
    $customer = CatalogFactory::customer($tenant, 'cus_4471', 'North Wind Ltd');

    $descriptor = app(CustomerDirectory::class)->find($tenant, 'cus_4471');

    expect($descriptor?->id->value)->toBe($customer->id->value)
        ->and($descriptor?->reference)->toBe('cus_4471');
});

it('answers "no such customer" rather than throwing', function (string $reference): void {
    $tenant = TenantFactory::tenant()->tenant();
    CatalogFactory::customer($tenant, 'cus_4471');

    expect(app(CustomerDirectory::class)->find($tenant, $reference))->toBeNull();
})->with([
    'a customer that does not exist' => ['cus_9999'],
    'a reference with a space' => ['cus 4471'],
    'an empty reference' => ['   '],
    'a reference longer than the column' => [str_repeat('c', 200)],
]);

it('does not resolve another tenant’s customer', function (): void {
    $acme = TenantFactory::tenant('acme')->tenant();
    $rival = TenantFactory::tenant('north-wind')->tenant();
    CatalogFactory::customer($acme, 'cus_4471');

    expect(app(CustomerDirectory::class)->find($rival, 'cus_4471'))->toBeNull();
});
