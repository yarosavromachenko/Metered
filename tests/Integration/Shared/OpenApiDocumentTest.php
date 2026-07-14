<?php

declare(strict_types=1);

use Dedoc\Scramble\Generator;
use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Support\Facades\Route;
use Metered\Tenancy\Domain\Scope;

use function Pest\Laravel\postJson;

use Tests\Support\TenantFactory;

/**
 * The OpenAPI document against the API it describes.
 *
 * The document is generated, which keeps it from going stale in the obvious
 * ways, and leaves the ways that matter: a route the generator cannot see, and
 * a response it describes from the framework's defaults rather than from what
 * this API sends. The last tests here send real requests and hold the answers
 * up against the schemas the document declares for them.
 */

function openApiDocument(): mixed
{
    return app(Generator::class)();
}

/**
 * Walks into a decoded document, answering null for anything that is not
 * there, so a missing branch fails the expectation that reads it rather than
 * the test before it gets that far.
 */
function dig(mixed $value, string|int ...$keys): mixed
{
    foreach ($keys as $key) {
        if (! is_array($value) || ! array_key_exists($key, $value)) {
            return null;
        }

        $value = $value[$key];
    }

    return $value;
}

/**
 * @return list<string>
 */
function keysOf(mixed $value): array
{
    return is_array($value) ? array_map(strval(...), array_keys($value)) : [];
}

function expectBodyMatchesSchema(mixed $body, string $schema): void
{
    $declared = dig(openApiDocument(), 'components', 'schemas', $schema);
    $required = dig($declared, 'required');

    expect(keysOf(dig($declared, 'properties')))->not->toBe([])
        ->and(array_diff(keysOf(array_flip(is_array($required) ? array_filter($required, is_string(...)) : [])), keysOf($body)))->toBe([])
        ->and(array_diff(keysOf($body), keysOf(dig($declared, 'properties'))))->toBe([]);
}

/**
 * @return array<string, string>
 */
function documentedApiHeaders(): array
{
    $project = TenantFactory::tenant();
    ['secret' => $secret] = TenantFactory::apiKey($project, [Scope::UsageWrite]);

    return ['Authorization' => 'Bearer ' . $secret->reveal()];
}

it('documents every API route the application registers', function (): void {
    $registered = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if ($route instanceof RegisteredRoute && str_starts_with($route->uri(), 'api/v1/')) {
            $registered[] = substr($route->uri(), strlen('api'));
        }
    }

    expect($registered)->not->toBe([])
        ->and(array_diff($registered, keysOf(dig(openApiDocument(), 'paths'))))->toBe([]);
});

it('describes the ingestion body with the fields the endpoint reads', function (): void {
    $body = dig(openApiDocument(), 'paths', '/v1/usage/events', 'post', 'requestBody', 'content', 'application/json', 'schema');

    expect(keysOf(dig($body, 'properties', 'events', 'items', 'properties')))
        ->toEqualCanonicalizing(['event_id', 'meter_code', 'customer_ref', 'quantity', 'occurred_at', 'properties'])
        ->and(dig($body, 'properties', 'events', 'items', 'required'))
        ->toEqualCanonicalizing(['event_id', 'meter_code', 'customer_ref', 'quantity', 'occurred_at'])
        ->and(dig($body, 'properties', 'events', 'maxItems'))->toEqual(100);
});

it('says every operation takes a bearer key and answers failures as problem+json', function (string $method, string $path): void {
    $document = openApiDocument();
    $responses = dig($document, 'paths', $path, $method, 'responses');

    expect(dig($document, 'components', 'securitySchemes'))->toContain(['type' => 'http', 'scheme' => 'bearer'])
        ->and(keysOf($responses))->toContain('401', '403', '422', '429');

    foreach ([401, 403, 429] as $status) {
        expect(keysOf(dig($responses, $status, 'content')))->toBe(['application/problem+json']);
    }

    expect(keysOf(dig($document, 'components', 'responses', 'ValidationException', 'content')))
        ->toBe(['application/problem+json']);
})->with([
    'ingestion' => ['post', '/v1/usage/events'],
    'reading usage' => ['get', '/v1/customers/{reference}/usage'],
]);

it('answers a request that fails validation in the shape the document declares', function (): void {
    $response = postJson('/api/v1/usage/events', ['events' => [['event_id' => 'evt_1']]], documentedApiHeaders())
        ->assertStatus(422);

    expect($response->json('type'))->toEndWith('/validation-failed');
    expectBodyMatchesSchema($response->json(), 'ValidationProblem');
});

it('answers an impossible event in the shape the document declares', function (): void {
    $response = postJson('/api/v1/usage/events', ['events' => [[
        'event_id' => 'evt_1',
        'meter_code' => 'api.requests',
        'customer_ref' => 'cus_4471',
        'quantity' => '-1',
        'occurred_at' => now()->toAtomString(),
    ]]], documentedApiHeaders())->assertStatus(422);

    expect($response->json('type'))->toEndWith('/invalid-event');
    expectBodyMatchesSchema($response->json(), 'ValidationProblem');
});

it('answers a request without a key in the shape the document declares', function (): void {
    $response = postJson('/api/v1/usage/events', ['events' => []])->assertStatus(401);

    expectBodyMatchesSchema($response->json(), 'Problem');
});
