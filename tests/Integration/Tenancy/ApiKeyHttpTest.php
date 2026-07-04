<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Metered\Shared\Presentation\Http\RequestAttributeScope;
use Metered\Shared\Presentation\Http\TenantRequest;
use Metered\Tenancy\Domain\ApiKeyRepository;
use Metered\Tenancy\Domain\Scope;

use function Pest\Laravel\getJson;

use Tests\Support\TenantFactory;

/**
 * The routes these tests exercise. Registered per test rather than in a
 * shared beforeEach, so each test states the middleware stack it is about.
 */
function tenantRoutes(): void
{
    Route::middleware(['api', 'api-key:usage:write'])->get('/api/v1/test-ingest', function (Request $request): JsonResponse {
        $tenant = TenantRequest::tenant($request);

        return response()->json([
            'organization' => $tenant->organizationId->value,
            'project' => $tenant->projectId->value,
            'key' => TenantRequest::apiKeyId($request)?->value,
            'idempotency_scope' => app(RequestAttributeScope::class)->forRequest($request),
        ]);
    });

    Route::middleware(['api', 'api-key'])->get('/api/v1/test-any-key', fn(): JsonResponse => response()->json(['ok' => true]));

    Route::middleware(['api', 'api-key', 'throttle-api-key:2'])
        ->get('/api/v1/test-throttled', fn(): JsonResponse => response()->json(['ok' => true]));
}

/**
 * @return array<string, string>
 */
function bearer(string $token): array
{
    return ['Authorization' => 'Bearer ' . $token];
}

it('puts the authenticated tenant on the request and nowhere else', function (): void {
    tenantRoutes();
    $project = TenantFactory::tenant();
    ['key' => $key, 'secret' => $secret] = TenantFactory::apiKey($project, [Scope::UsageWrite]);

    getJson('/api/v1/test-ingest', bearer($secret->reveal()))
        ->assertOk()
        ->assertJson([
            'organization' => $project->organizationId->value,
            'project' => $project->id->value,
            'key' => $key->id->value,
            // Idempotency keys are scoped per project, and this is where that
            // scope comes from once keys exist.
            'idempotency_scope' => $project->id->value,
        ]);
});

it('refuses a request that carries no key', function (): void {
    tenantRoutes();

    getJson('/api/v1/test-any-key')
        ->assertStatus(401)
        ->assertHeader('WWW-Authenticate', 'Bearer realm="metered"')
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://metered.dev/problems/invalid-api-key');
});

it('says a key was revoked rather than merely refusing it', function (): void {
    tenantRoutes();
    $project = TenantFactory::tenant();
    ['key' => $key, 'secret' => $secret] = TenantFactory::apiKey($project);

    app(ApiKeyRepository::class)->save($key->revoke(now()->toDateTimeImmutable()));

    getJson('/api/v1/test-any-key', bearer($secret->reveal()))
        ->assertStatus(401)
        ->assertJsonPath('type', 'https://metered.dev/problems/revoked-api-key');
});

it('refuses a valid key that lacks the scope the route needs', function (): void {
    tenantRoutes();
    $project = TenantFactory::tenant();
    ['secret' => $secret] = TenantFactory::apiKey($project, [Scope::Admin]);

    getJson('/api/v1/test-ingest', bearer($secret->reveal()))
        ->assertStatus(403)
        ->assertJsonPath('type', 'https://metered.dev/problems/insufficient-scope')
        ->assertJsonPath('required_scope', 'usage:write');
});

it('never leaks one tenant into the next request on the same worker', function (): void {
    tenantRoutes();

    $acme = TenantFactory::tenant('acme');
    $rival = TenantFactory::tenant('north-wind');
    ['secret' => $acmeSecret] = TenantFactory::apiKey($acme);
    ['secret' => $rivalSecret] = TenantFactory::apiKey($rival);

    // Three requests through one booted application, which is what an Octane
    // worker is: any tenant state kept outside the request would survive from
    // one to the next.
    getJson('/api/v1/test-ingest', bearer($acmeSecret->reveal()))
        ->assertOk()
        ->assertJsonPath('project', $acme->id->value);

    getJson('/api/v1/test-ingest', bearer($rivalSecret->reveal()))
        ->assertOk()
        ->assertJsonPath('project', $rival->id->value);

    // And an unauthenticated request must not inherit either of them: it is
    // refused before any handler runs.
    getJson('/api/v1/test-ingest')->assertStatus(401);
});

it('counts requests per key and answers 429 with the documented headers', function (): void {
    tenantRoutes();
    $project = TenantFactory::tenant();
    ['secret' => $secret] = TenantFactory::apiKey($project);

    getJson('/api/v1/test-throttled', bearer($secret->reveal()))
        ->assertOk()
        ->assertHeader('RateLimit-Limit', '2')
        ->assertHeader('RateLimit-Remaining', '1');

    getJson('/api/v1/test-throttled', bearer($secret->reveal()))
        ->assertOk()
        ->assertHeader('RateLimit-Remaining', '0');

    $response = getJson('/api/v1/test-throttled', bearer($secret->reveal()))
        ->assertStatus(429)
        ->assertHeader('RateLimit-Remaining', '0')
        ->assertJsonPath('type', 'https://metered.dev/problems/rate-limit-exceeded');

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('gives each key its own budget', function (): void {
    tenantRoutes();
    $project = TenantFactory::tenant();
    ['secret' => $first] = TenantFactory::apiKey($project, name: 'First');
    ['secret' => $second] = TenantFactory::apiKey($project, name: 'Second');

    getJson('/api/v1/test-throttled', bearer($first->reveal()))->assertOk();
    getJson('/api/v1/test-throttled', bearer($first->reveal()))->assertOk();
    getJson('/api/v1/test-throttled', bearer($first->reveal()))->assertStatus(429);

    // A second key on the same project is a separate bucket: the limit is per
    // credential, not per tenant.
    getJson('/api/v1/test-throttled', bearer($second->reveal()))->assertOk();
});
