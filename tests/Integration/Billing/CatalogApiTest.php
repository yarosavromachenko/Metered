<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Metered\Billing\Domain\Plan\PlanVersion;
use Metered\Billing\Domain\Plan\PlanVersionRepository;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Domain\Scope;

use function Pest\Laravel\getJson;
use function Pest\Laravel\json;
use function Pest\Laravel\postJson;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\CatalogFactory;
use Tests\Support\TenantFactory;

/**
 * @param list<Scope> $scopes
 *
 * @return array{tenant: TenantContext, headers: array<string, string>}
 */
function catalogProject(string $slug = 'acme', array $scopes = [Scope::Admin]): array
{
    $project = TenantFactory::tenant($slug);
    ['secret' => $secret] = TenantFactory::apiKey($project, $scopes);

    return ['tenant' => $project->tenant(), 'headers' => ['Authorization' => 'Bearer ' . $secret->reveal()]];
}

/**
 * A write to the management API, with the Idempotency-Key every write needs.
 *
 * @param array<string, mixed> $body
 * @param array<string, string> $headers
 *
 * @return TestResponse<Response>
 */
function catalogWrite(string $path, array $body, array $headers): TestResponse
{
    return postJson($path, $body, $headers + ['Idempotency-Key' => (string) Str::uuid()]);
}

/**
 * The id of what a request just created.
 *
 * @param TestResponse<Response> $response
 */
function createdId(TestResponse $response): string
{
    $id = $response->assertCreated()->json('id');

    return is_string($id) ? $id : throw new LogicException('The response carries no id.');
}

it('builds a catalog and runs a subscription through it, end to end', function (): void {
    app()->instance(ClockInterface::class, $clock = new MockClock('2026-01-31 14:00:00', 'UTC'));
    ['headers' => $headers] = catalogProject();

    catalogWrite('/api/v1/meters', ['code' => 'API.Requests', 'name' => 'API requests', 'aggregation' => 'count'], $headers)
        ->assertCreated()
        ->assertJsonPath('code', 'api.requests');
    catalogWrite('/api/v1/customers', ['reference' => 'cus_4471', 'name' => 'Acme Corp'], $headers)->assertCreated();

    $plan = createdId(catalogWrite('/api/v1/plans', ['code' => 'pro', 'name' => 'Pro'], $headers));

    $version = createdId(catalogWrite("/api/v1/plans/{$plan}/versions", [
        'interval' => 'month',
        'prices' => [
            ['model' => 'flat_fee', 'amount' => 4900],
            ['model' => 'graduated', 'meter' => 'api.requests', 'tiers' => [
                ['up_to' => '1000', 'unit_price' => '0'],
                ['up_to' => null, 'unit_price' => '0.002'],
            ]],
        ],
    ], $headers)
        ->assertCreated()
        ->assertJsonPath('number', 1)
        ->assertJsonPath('currency', 'EUR')
        ->assertJsonPath('prices.0.amount', 4900)
        ->assertJsonPath('prices.1.meter', 'api.requests')
        ->assertJsonPath('prices.1.tiers.0.up_to', '1000.000000')
        ->assertJsonPath('prices.1.tiers.1.up_to', null)
        ->assertJsonPath('prices.1.tiers.1.unit_price', '0.00200000'));

    expect(getJson('/api/v1/plans', $headers)->assertOk()->json('data.0.versions.0.published_at'))->not->toBeNull();

    $subscription = createdId(catalogWrite('/api/v1/subscriptions', ['customer_ref' => 'cus_4471', 'plan_version_id' => $version], $headers)
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('anchor_at', '2026-01-31T14:00:00+00:00'));

    $clock->modify('2026-02-10 00:00:00');

    catalogWrite("/api/v1/subscriptions/{$subscription}/cancel", [], $headers)
        ->assertOk()
        ->assertJsonPath('status', 'pending_cancellation')
        ->assertJsonPath('ends_at', '2026-02-28T14:00:00+00:00');

    // Every change names the key that made it.
    expect(DB::table('audit_log')->where('actor', 'like', 'api-key:%')->orderBy('id')->pluck('action')->all())->toBe([
        'meter.defined', 'customer.registered', 'plan.created', 'plan_version.drafted',
        'price.added', 'price.added', 'plan_version.published', 'subscription.started', 'subscription.canceled',
    ]);
});

it('changes a subscription\'s plan at the period end', function (): void {
    app()->instance(ClockInterface::class, new MockClock('2026-03-15 00:00:00', 'UTC'));
    ['tenant' => $tenant, 'headers' => $headers] = catalogProject();
    $customer = CatalogFactory::customer($tenant);
    $subscription = CatalogFactory::subscription($customer, CatalogFactory::version(CatalogFactory::plan($tenant, 'starter')), new DateTimeImmutable('2026-03-01T00:00:00+00:00'));
    $pro = CatalogFactory::version(CatalogFactory::plan($tenant, 'pro'));

    catalogWrite("/api/v1/subscriptions/{$subscription->id->value}/change-plan", ['plan_version_id' => $pro->id->value], $headers)
        ->assertOk()
        ->assertJsonPath('phases.1.plan_version_id', $pro->id->value)
        ->assertJsonPath('phases.1.starts_at', '2026-04-01T00:00:00+00:00');
});

it('leaves a draft when asked, and nothing at all when a price is refused', function (): void {
    ['tenant' => $tenant, 'headers' => $headers] = catalogProject();
    $plan = CatalogFactory::plan($tenant);

    catalogWrite("/api/v1/plans/{$plan->id->value}/versions", [
        'interval' => 'month',
        'publish' => false,
        'prices' => [['model' => 'flat_fee', 'amount' => 100]],
    ], $headers)->assertCreated()->assertJsonPath('published_at', null);

    catalogWrite("/api/v1/plans/{$plan->id->value}/versions", [
        'interval' => 'month',
        'prices' => [
            ['model' => 'flat_fee', 'amount' => 100],
            // A bounded last tier: the domain refuses it after the flat fee
            // was already added to the draft.
            ['model' => 'volume', 'meter' => CatalogFactory::meter($tenant)->code->value, 'tiers' => [['up_to' => '10', 'unit_price' => '1']]],
        ],
    ], $headers)
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://metered.dev/problems/rule-violated')
        ->assertJsonPath('detail', 'The last tier must be unbounded, or a quantity beyond it would have no price.');

    expect(array_map(static fn(PlanVersion $v): int => $v->number, app(PlanVersionRepository::class)->listForPlan($tenant, $plan->id)))->toBe([1]);
});

it('answers what went wrong with the request, field by field or rule by rule', function (string $method, string $path, array $body, int $status, string $type): void {
    ['tenant' => $tenant, 'headers' => $headers] = catalogProject();
    CatalogFactory::meter($tenant, 'api.requests');
    CatalogFactory::plan($tenant, 'pro');

    $path = str_replace('{plan}', CatalogFactory::plan($tenant, 'team')->id->value, $path);

    json($method, $path, $body, $headers + ['Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus($status)
        ->assertJsonPath('type', 'https://metered.dev/problems/' . $type);
})->with([
    'an unknown aggregation' => ['POST', '/api/v1/meters', ['code' => 'x.y', 'name' => 'X', 'aggregation' => 'avg'], 422, 'validation-failed'],
    'a malformed meter code' => ['POST', '/api/v1/meters', ['code' => 'api requests', 'name' => 'X', 'aggregation' => 'sum'], 422, 'rule-violated'],
    'a meter code taken' => ['POST', '/api/v1/meters', ['code' => 'api.requests', 'name' => 'X', 'aggregation' => 'sum'], 409, 'conflict'],
    'a plan code taken' => ['POST', '/api/v1/plans', ['code' => 'pro', 'name' => 'Pro'], 409, 'conflict'],
    'a price on a meter that does not exist' => ['POST', '/api/v1/plans/{plan}/versions', ['interval' => 'month', 'prices' => [['model' => 'per_unit', 'meter' => 'nope', 'unit_price' => '1']]], 422, 'validation-failed'],
    'a flat fee naming a meter' => ['POST', '/api/v1/plans/{plan}/versions', ['interval' => 'month', 'prices' => [['model' => 'flat_fee', 'amount' => 1, 'meter' => 'api.requests']]], 422, 'validation-failed'],
    'a price too precise' => ['POST', '/api/v1/plans/{plan}/versions', ['interval' => 'month', 'prices' => [['model' => 'per_unit', 'meter' => 'api.requests', 'unit_price' => '0.000000001']]], 422, 'rule-violated'],
    'a plan that does not exist' => ['POST', '/api/v1/plans/01924b7c-0000-7000-8000-000000000999/versions', ['interval' => 'month', 'prices' => [['model' => 'flat_fee', 'amount' => 1]]], 404, 'not-found'],
    'a customer that does not exist' => ['POST', '/api/v1/subscriptions', ['customer_ref' => 'nobody', 'plan_version_id' => '01924b7c-0000-7000-8000-000000000999'], 404, 'not-found'],
]);

it('shows and changes nothing of another project\'s catalog', function (): void {
    ['tenant' => $acme, 'headers' => $acmeHeaders] = catalogProject('acme');
    ['tenant' => $rival] = catalogProject('north-wind');
    CatalogFactory::meter($rival, 'their.meter');
    CatalogFactory::customer($rival, 'their_customer');
    $theirPlan = CatalogFactory::plan($rival, 'theirs');
    $theirVersion = CatalogFactory::version($theirPlan);
    $theirSubscription = CatalogFactory::subscription(CatalogFactory::customer($rival, 'cus_x'), $theirVersion);
    CatalogFactory::customer($acme, 'cus_4471');

    getJson('/api/v1/meters', $acmeHeaders)->assertOk()->assertJsonCount(0, 'data');
    getJson('/api/v1/customers', $acmeHeaders)->assertOk()->assertJsonCount(1, 'data');
    getJson('/api/v1/plans', $acmeHeaders)->assertOk()->assertJsonCount(0, 'data');

    catalogWrite("/api/v1/plans/{$theirPlan->id->value}/versions", ['interval' => 'month', 'prices' => [['model' => 'flat_fee', 'amount' => 1]]], $acmeHeaders)->assertNotFound();
    catalogWrite('/api/v1/subscriptions', ['customer_ref' => 'cus_4471', 'plan_version_id' => $theirVersion->id->value], $acmeHeaders)->assertNotFound();
    catalogWrite("/api/v1/subscriptions/{$theirSubscription->id->value}/cancel", ['immediately' => true], $acmeHeaders)->assertNotFound();
});

it('asks every write for an Idempotency-Key, and replays a retried one', function (): void {
    ['headers' => $headers] = catalogProject();
    $retried = $headers + ['Idempotency-Key' => '5f0c7f0e-6a57-4f1b-9d3e-0c9b1f6e2a11'];

    postJson('/api/v1/plans', ['code' => 'pro', 'name' => 'Pro'], $headers)
        ->assertStatus(400)
        ->assertJsonPath('type', 'https://metered.dev/problems/idempotency-key-required');

    $first = postJson('/api/v1/plans', ['code' => 'pro', 'name' => 'Pro'], $retried)->assertCreated();

    postJson('/api/v1/plans', ['code' => 'pro', 'name' => 'Pro'], $retried)
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('id', $first->json('id'));
});

it('is closed to a key that may only report usage', function (string $method, string $path): void {
    ['headers' => $headers] = catalogProject(scopes: [Scope::UsageWrite]);

    json($method, $path, [], $headers + ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();
})->with([
    ['GET', '/api/v1/meters'],
    ['POST', '/api/v1/meters'],
    ['GET', '/api/v1/plans'],
    ['POST', '/api/v1/subscriptions'],
]);
