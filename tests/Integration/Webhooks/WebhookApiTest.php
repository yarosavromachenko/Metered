<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Metered\Tenancy\Domain\Scope;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

use Symfony\Component\HttpFoundation\Response;
use Tests\Support\TenantFactory;

/**
 * @param list<Scope> $scopes
 *
 * @return array<string, string>
 */
function webhookKey(string $slug = 'acme', array $scopes = [Scope::Admin]): array
{
    ['secret' => $secret] = TenantFactory::apiKey(TenantFactory::tenant($slug), $scopes);

    return ['Authorization' => 'Bearer ' . $secret->reveal()];
}

/**
 * @param array<string, string> $headers
 *
 * @return array<string, string>
 */
function withIdempotencyKey(array $headers): array
{
    return $headers + ['Idempotency-Key' => (string) Str::uuid()];
}

/**
 * @param array<string, string> $headers
 *
 * @return TestResponse<Response>
 */
function registerEndpoint(array $headers, string $url = 'https://hooks.example.com/metered'): TestResponse
{
    return postJson('/api/v1/webhook-endpoints', ['url' => $url, 'events' => ['invoice.paid', 'invoice.voided'], 'description' => 'Billing sync'], withIdempotencyKey($headers));
}

/**
 * @param TestResponse<Response> $response
 */
function idOf(TestResponse $response): string
{
    $id = $response->json('id');

    return is_string($id) ? $id : throw new LogicException('No id in the response.');
}

it('registers an endpoint and shows its secret in that answer only', function (): void {
    $headers = webhookKey();

    $created = registerEndpoint($headers)->assertCreated()
        ->assertJsonPath('url', 'https://hooks.example.com/metered')
        ->assertJsonPath('events', ['invoice.paid', 'invoice.voided'])
        ->assertJsonPath('breaker.state', 'closed');
    $secret = $created->json('signing_secret');

    expect($secret)->toBeString()->toStartWith('whsec_')
        ->and($created->json('secret'))->toBe('whsec_…' . substr(is_string($secret) ? $secret : '', -4));

    getJson('/api/v1/webhook-endpoints', $headers)->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonMissingPath('data.0.signing_secret')
        ->assertJsonMissingPath('data.0.secret')
        ->assertJsonPath('data.0.id', idOf($created));
});

it('changes only what a PATCH names, and rotates the secret on request', function (): void {
    $headers = webhookKey();
    $id = idOf(registerEndpoint($headers));

    patchJson('/api/v1/webhook-endpoints/' . $id, ['enabled' => false], withIdempotencyKey($headers))->assertOk()
        ->assertJsonPath('enabled', false)
        ->assertJsonPath('url', 'https://hooks.example.com/metered')
        ->assertJsonPath('events', ['invoice.paid', 'invoice.voided'])
        ->assertJsonMissingPath('signing_secret');

    $rotated = postJson('/api/v1/webhook-endpoints/' . $id . '/rotate-secret', [], withIdempotencyKey($headers))->assertOk()
        ->assertJsonPath('id', $id);

    expect($rotated->json('signing_secret'))->toBeString()->toStartWith('whsec_');
});

it('refuses what an endpoint cannot be, in the problem format', function (array $body, string $detail): void {
    postJson('/api/v1/webhook-endpoints', $body, withIdempotencyKey(webhookKey()))->assertUnprocessable()
        ->assertJsonPath('detail', $detail);
})->with([
    'plain http' => [['url' => 'http://hooks.example.com', 'events' => ['invoice.paid']], 'That webhook URL cannot be used: deliveries go over https only.'],
    'an unknown event' => [['url' => 'https://hooks.example.com', 'events' => ['invoice.pay']], '"invoice.pay" is not an event an endpoint can listen to; the events are subscription.created, subscription.canceled, invoice.finalized, invoice.paid, invoice.voided.'],
]);

it('lists deliveries and replays a dead one', function (): void {
    $headers = webhookKey();
    $endpoint = idOf(registerEndpoint($headers));
    $project = DB::table('webhook_endpoints')->where('id', $endpoint)->first(['organization_id', 'project_id']);
    DB::table('webhook_deliveries')->insert([
        'id' => '01924b7c-0000-7000-8000-00000000f101', 'organization_id' => $project?->organization_id, 'project_id' => $project?->project_id,
        'endpoint_id' => $endpoint, 'event_id' => '01924b7c-0000-7000-8000-00000000f201', 'event_type' => 'invoice.paid',
        'body' => '{}', 'status' => 'dead', 'attempts' => 10, 'next_attempt_at' => null, 'last_status_code' => 503, 'created_at' => '2026-09-25 00:00:00+00',
    ]);

    getJson('/api/v1/webhook-deliveries?status=dead', $headers)->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.attempts', 10)
        ->assertJsonPath('data.0.last_status_code', 503);

    postJson('/api/v1/webhook-deliveries/01924b7c-0000-7000-8000-00000000f101/replay', [], withIdempotencyKey($headers))->assertOk()
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('attempts', 0);

    postJson('/api/v1/webhook-deliveries/01924b7c-0000-7000-8000-00000000f101/replay', [], withIdempotencyKey($headers))->assertUnprocessable()
        ->assertJsonPath('detail', 'A pending delivery cannot be replayed; only a dead or failed one can.');
});

it('removes an endpoint, and then knows nothing of it', function (): void {
    $headers = webhookKey();
    $id = idOf(registerEndpoint($headers));

    deleteJson('/api/v1/webhook-endpoints/' . $id, [], withIdempotencyKey($headers))->assertNoContent();
    postJson('/api/v1/webhook-endpoints/' . $id . '/rotate-secret', [], withIdempotencyKey($headers))->assertNotFound();
});

it('answers 404 for another project’s endpoint, and for an id that is not one', function (): void {
    $theirs = idOf(registerEndpoint(webhookKey('north-wind')));
    $headers = webhookKey();

    patchJson('/api/v1/webhook-endpoints/' . $theirs, ['enabled' => false], withIdempotencyKey($headers))->assertNotFound();
    deleteJson('/api/v1/webhook-endpoints/' . $theirs, [], withIdempotencyKey($headers))->assertNotFound();
    postJson('/api/v1/webhook-deliveries/not-a-delivery/replay', [], withIdempotencyKey($headers))->assertNotFound()
        ->assertJsonPath('detail', 'No webhook delivery not-a-delivery in this project.');
});

it('keeps webhooks from a key that only reports usage', function (): void {
    $headers = webhookKey('acme', [Scope::UsageWrite]);

    getJson('/api/v1/webhook-endpoints', $headers)->assertForbidden();
    registerEndpoint($headers)->assertForbidden();
});

it('replays from the command line by id', function (): void {
    $headers = webhookKey();
    $endpoint = idOf(registerEndpoint($headers));
    $project = DB::table('webhook_endpoints')->where('id', $endpoint)->first(['organization_id', 'project_id']);
    DB::table('webhook_deliveries')->insert([
        'id' => '01924b7c-0000-7000-8000-00000000f102', 'organization_id' => $project?->organization_id, 'project_id' => $project?->project_id,
        'endpoint_id' => $endpoint, 'event_id' => '01924b7c-0000-7000-8000-00000000f202', 'event_type' => 'invoice.paid',
        'body' => '{}', 'status' => 'failed', 'attempts' => 1, 'next_attempt_at' => null, 'created_at' => '2026-09-25 00:00:00+00',
    ]);

    expect(Artisan::call('webhooks:replay', ['delivery' => '01924b7c-0000-7000-8000-00000000f102']))->toBe(0)
        ->and(Artisan::call('webhooks:replay', ['delivery' => 'nope']))->toBe(1)
        ->and(DB::table('webhook_deliveries')->where('id', '01924b7c-0000-7000-8000-00000000f102')->value('status'))->toBe('pending');
});
