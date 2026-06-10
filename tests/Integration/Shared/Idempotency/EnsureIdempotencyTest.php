<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Metered\Shared\Application\Idempotency\IdempotencyStore;
use Metered\Shared\Presentation\Http\Middleware\EnsureIdempotency;

use function Pest\Laravel\postJson;

use Tests\Support\CallLog;

/**
 * Registers the routes a test needs and hands back the log they write to.
 *
 * Built per test rather than in beforeEach so the log is an ordinary typed
 * variable instead of an untyped property on the test case.
 */
function idempotentRoutes(): CallLog
{
    $log = new CallLog();

    Route::middleware(['api', 'idempotent'])->post('/api/v1/test-subscriptions', function (Request $request) use ($log): JsonResponse {
        $log->record('executed');

        return response()->json(['name' => $request->input('name')], 201);
    });

    Route::middleware(['api', 'idempotent'])->post('/api/v1/test-explodes', function () use ($log): never {
        $log->record('executed');

        throw new RuntimeException('the handler blew up');
    });

    Route::middleware(['api', 'idempotent'])->post('/api/v1/test-unavailable', function () use ($log): JsonResponse {
        $log->record('executed');

        return response()->json(['problem' => true], 503);
    });

    return $log;
}

it('refuses a mutating request that carries no key', function (): void {
    $log = idempotentRoutes();

    $response = postJson('/api/v1/test-subscriptions', ['name' => 'acme']);

    $response->assertStatus(400)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://metered.dev/problems/idempotency-key-required')
        ->assertJsonPath('status', 400);

    expect($log->entries)->toBe([]);
});

it('executes the first request and remembers what it answered', function (): void {
    $log = idempotentRoutes();

    $response = postJson(
        '/api/v1/test-subscriptions',
        ['name' => 'acme'],
        [EnsureIdempotency::HEADER => 'key-1'],
    );

    $response->assertStatus(201)->assertJsonPath('name', 'acme');
    expect($log->entries)->toBe(['executed']);
});

it('replays the stored answer instead of executing again', function (): void {
    $log = idempotentRoutes();
    $headers = [EnsureIdempotency::HEADER => 'key-1'];

    $first = postJson('/api/v1/test-subscriptions', ['name' => 'acme'], $headers);
    $second = postJson('/api/v1/test-subscriptions', ['name' => 'acme'], $headers);

    expect($log->entries)->toBe(['executed']);

    $second->assertStatus(201)
        ->assertHeader('Idempotent-Replayed', 'true')
        // Byte for byte what the first request answered, not merely something
        // equivalent: a replay that reformats its body is a different response.
        ->assertJsonPath('name', 'acme');

    expect($second->json())->toBe($first->json());
});

it('rejects the same key used for a different request', function (): void {
    $log = idempotentRoutes();
    $headers = [EnsureIdempotency::HEADER => 'key-1'];

    postJson('/api/v1/test-subscriptions', ['name' => 'acme'], $headers);
    $response = postJson('/api/v1/test-subscriptions', ['name' => 'globex'], $headers);

    $response->assertStatus(422)
        ->assertJsonPath('type', 'https://metered.dev/problems/idempotency-key-reused');

    // The second request never ran: a reused key is a client bug, and running
    // it would be the very duplicate the header exists to prevent.
    expect($log->entries)->toBe(['executed']);
});

it('tells a client to wait while an identical request is still running', function (): void {
    $log = idempotentRoutes();

    // Claim the key as another request would have, and leave it in progress.
    app(IdempotencyStore::class)->claim(
        'unscoped',
        'key-1',
        hash('sha256', implode("\n", ['POST', 'api/v1/test-subscriptions', '{"name":"acme"}'])),
    );

    $response = postJson('/api/v1/test-subscriptions', ['name' => 'acme'], [EnsureIdempotency::HEADER => 'key-1']);

    $response->assertStatus(409)
        ->assertHeader('Retry-After', '1')
        ->assertJsonPath('type', 'https://metered.dev/problems/idempotency-key-in-progress');

    expect($log->entries)->toBe([]);
});

it('releases the key when the request failed, so a retry is a real retry', function (): void {
    $log = idempotentRoutes();
    $headers = [EnsureIdempotency::HEADER => 'key-1'];

    postJson('/api/v1/test-explodes', [], $headers);
    postJson('/api/v1/test-explodes', [], $headers);

    // Nothing was carried out either time, so the client deserves a real second
    // attempt rather than a replayed failure.
    expect($log->entries)->toBe(['executed', 'executed']);
});

it('does not remember a server error as an outcome', function (): void {
    $log = idempotentRoutes();
    $headers = [EnsureIdempotency::HEADER => 'key-1'];

    postJson('/api/v1/test-unavailable', [], $headers)->assertStatus(503);
    postJson('/api/v1/test-unavailable', [], $headers)->assertStatus(503);

    expect($log->entries)->toBe(['executed', 'executed']);
});

it('keeps keys of different scopes apart', function (): void {
    $store = app(IdempotencyStore::class);
    $fingerprint = hash('sha256', 'anything');

    expect($store->claim('project-a', 'key-1', $fingerprint)->status->name)->toBe('Claimed')
        ->and($store->claim('project-b', 'key-1', $fingerprint)->status->name)->toBe('Claimed');
});
