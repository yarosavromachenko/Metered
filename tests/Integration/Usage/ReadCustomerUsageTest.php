<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Metered\Shared\Domain\Metering\Aggregation;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Domain\Scope;

use function Pest\Laravel\getJson;

use Tests\Support\CatalogFactory;
use Tests\Support\TenantFactory;

/**
 * The read side of usage: what a client's own dashboard asks for.
 *
 * Fixtures are written straight into the aggregates, because that is what
 * this endpoint reads and what an invoice will read — the path from events to
 * aggregates has its own tests.
 */
/**
 * @return array{tenant: TenantContext, headers: array<string, string>}
 */
function usageProject(string $slug = 'acme'): array
{
    $project = TenantFactory::tenant($slug);
    ['secret' => $secret] = TenantFactory::apiKey($project, [Scope::Admin]);

    return [
        'tenant' => $project->tenant(),
        'headers' => ['Authorization' => 'Bearer ' . $secret->reveal()],
    ];
}

function aggregateRow(
    TenantContext $tenant,
    string $customerId,
    string $meterId,
    string $bucket,
    string $quantity,
    int $events = 1,
    string $meterCode = 'api.requests',
    string $customerRef = 'cus_4471',
): void {
    DB::table('usage_aggregates')->insert([
        'organization_id' => $tenant->organizationId->value,
        'project_id' => $tenant->projectId->value,
        'customer_id' => $customerId,
        'meter_id' => $meterId,
        'meter_code' => $meterCode,
        'customer_ref' => $customerRef,
        'bucket_start' => $bucket,
        'quantity' => $quantity,
        'event_count' => $events,
        'updated_at' => $bucket,
    ]);
}

it('totals a customer’s usage per meter over the window asked for', function (): void {
    ['tenant' => $tenant, 'headers' => $headers] = usageProject();
    $meter = CatalogFactory::meter($tenant, 'api.requests');
    $customer = CatalogFactory::customer($tenant, 'cus_4471');

    aggregateRow($tenant, $customer->id->value, $meter->id->value, '2026-09-22T10:00:00+00:00', '10.000000', 4);
    aggregateRow($tenant, $customer->id->value, $meter->id->value, '2026-09-22T11:00:00+00:00', '5.000000', 2);
    aggregateRow($tenant, $customer->id->value, $meter->id->value, '2026-09-25T11:00:00+00:00', '99.000000', 9);

    getJson('/api/v1/customers/cus_4471/usage?from=2026-09-22T00:00:00%2B00:00&to=2026-09-23T00:00:00%2B00:00', $headers)
        ->assertOk()
        ->assertJsonPath('customer_ref', 'cus_4471')
        ->assertJsonPath('meters.0.meter_code', 'api.requests')
        ->assertJsonPath('meters.0.quantity', '15.000000')
        ->assertJsonPath('meters.0.events', 6);
});

it('takes the peak of a max meter rather than adding its hours together', function (): void {
    ['tenant' => $tenant, 'headers' => $headers] = usageProject();
    $meter = CatalogFactory::meter($tenant, 'seats.peak', Aggregation::Max);
    $customer = CatalogFactory::customer($tenant, 'cus_4471');

    aggregateRow($tenant, $customer->id->value, $meter->id->value, '2026-09-22T10:00:00+00:00', '12.000000', meterCode: 'seats.peak');
    aggregateRow($tenant, $customer->id->value, $meter->id->value, '2026-09-22T11:00:00+00:00', '7.000000', meterCode: 'seats.peak');

    // Adding hourly peaks would give 19 seats, which is a number that means
    // nothing and looks entirely plausible on an invoice.
    getJson('/api/v1/customers/cus_4471/usage?from=2026-09-22T00:00:00%2B00:00&to=2026-09-23T00:00:00%2B00:00', $headers)
        ->assertOk()
        ->assertJsonPath('meters.0.quantity', '12.000000')
        ->assertJsonPath('meters.0.aggregation', 'max');
});

it('narrows to one meter when asked, by the code however it is capitalised', function (): void {
    ['tenant' => $tenant, 'headers' => $headers] = usageProject();
    $requests = CatalogFactory::meter($tenant, 'api.requests');
    $storage = CatalogFactory::meter($tenant, 'storage.gb');
    $customer = CatalogFactory::customer($tenant, 'cus_4471');

    aggregateRow($tenant, $customer->id->value, $requests->id->value, '2026-09-22T10:00:00+00:00', '10.000000');
    aggregateRow($tenant, $customer->id->value, $storage->id->value, '2026-09-22T10:00:00+00:00', '3.000000', meterCode: 'storage.gb');

    $response = getJson(
        '/api/v1/customers/cus_4471/usage?meter=API.Requests'
        . '&from=2026-09-22T00:00:00%2B00:00&to=2026-09-23T00:00:00%2B00:00',
        $headers,
    )->assertOk();

    expect($response->json('meters'))->toHaveCount(1)
        ->and($response->json('meters.0.meter_code'))->toBe('api.requests');
});

it('answers 404 for a customer this project does not have', function (): void {
    ['headers' => $headers] = usageProject();

    // "No usage" and "no such customer" are different answers, and a client
    // integrating against this has to be able to tell them apart.
    getJson('/api/v1/customers/cus_nobody/usage', $headers)
        ->assertStatus(404)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://metered.dev/problems/customer-not-found');
});

it('answers an empty summary for a customer who has used nothing', function (): void {
    ['tenant' => $tenant, 'headers' => $headers] = usageProject();
    CatalogFactory::customer($tenant, 'cus_quiet');

    getJson('/api/v1/customers/cus_quiet/usage', $headers)
        ->assertOk()
        ->assertJsonPath('meters', []);
});

it('never shows one tenant another tenant’s usage', function (): void {
    $acme = usageProject('acme');
    $rival = usageProject('north-wind');

    $meter = CatalogFactory::meter($acme['tenant'], 'api.requests');
    $customer = CatalogFactory::customer($acme['tenant'], 'cus_4471');
    aggregateRow($acme['tenant'], $customer->id->value, $meter->id->value, '2026-09-22T10:00:00+00:00', '10.000000');

    // Same reference, other project: theirs does not exist over here.
    getJson('/api/v1/customers/cus_4471/usage?from=2026-09-22T00:00:00%2B00:00&to=2026-09-23T00:00:00%2B00:00', $rival['headers'])
        ->assertStatus(404);
});

it('refuses an ingestion key, which may report usage but not read it', function (): void {
    $project = TenantFactory::tenant();
    ['secret' => $secret] = TenantFactory::apiKey($project, [Scope::UsageWrite]);

    getJson('/api/v1/customers/cus_4471/usage', ['Authorization' => 'Bearer ' . $secret->reveal()])
        ->assertStatus(403);
});

it('refuses a window that is not a window', function (): void {
    ['headers' => $headers] = usageProject();

    getJson('/api/v1/customers/cus_4471/usage?from=last%20tuesday', $headers)->assertStatus(422);
});

it('ignores an aggregate id that belongs to another customer', function (): void {
    ['tenant' => $tenant, 'headers' => $headers] = usageProject();
    $meter = CatalogFactory::meter($tenant, 'api.requests');
    $mine = CatalogFactory::customer($tenant, 'cus_4471');
    $theirs = CatalogFactory::customer($tenant, 'cus_9999');

    aggregateRow($tenant, $mine->id->value, $meter->id->value, '2026-09-22T10:00:00+00:00', '10.000000');
    aggregateRow($tenant, $theirs->id->value, $meter->id->value, '2026-09-22T10:00:00+00:00', '99.000000', customerRef: 'cus_9999');

    getJson('/api/v1/customers/cus_4471/usage?from=2026-09-22T00:00:00%2B00:00&to=2026-09-23T00:00:00%2B00:00', $headers)
        ->assertOk()
        ->assertJsonPath('meters.0.quantity', '10.000000');
});

it('is a read that resolves the customer by the reference their events carry', function (): void {
    ['tenant' => $tenant, 'headers' => $headers] = usageProject();
    CatalogFactory::customer($tenant, 'CUS_Case');

    // The reference keeps its case, here as everywhere else.
    getJson('/api/v1/customers/CUS_Case/usage', $headers)->assertOk();
    getJson('/api/v1/customers/cus_case/usage', $headers)->assertStatus(404);
});
