<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

beforeEach(function (): void {
    Route::middleware('api')->post('/api/v1/test-validated', function (Request $request) {
        validator($request->all(), [
            'meter' => 'required|string',
            'quantity' => 'required|numeric',
            'customer.external_id' => 'required|string',
        ])->validate();

        return response()->json(['ok' => true]);
    });

    Route::middleware('api')->get('/api/v1/test-forbidden', function (): never {
        throw new AccessDeniedHttpException();
    });

    Route::middleware('api')->get('/api/v1/test-overloaded', function (): never {
        throw new ServiceUnavailableHttpException(30);
    });

    Route::middleware('api')->get('/api/v1/test-broken', function (): never {
        throw new RuntimeException('connection string: postgres://user:hunter2@db/metered');
    });
});


it('answers validation failures as a problem document with pointers', function (): void {
    $response = postJson('/api/v1/test-validated', ['quantity' => 'lots']);

    $response->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://metered.dev/problems/validation-failed')
        ->assertJsonPath('title', 'Validation failed')
        ->assertJsonPath('status', 422)
        ->assertJsonPath('instance', '/api/v1/test-validated');

    $errors = $response->json('errors');
    expect($errors)->toBeArray();

    $pointers = array_column(is_array($errors) ? $errors : [], 'pointer');

    // JSON pointers, so a client can map a message to a field without parsing
    // prose — including into nested objects.
    expect($pointers)->toContain('/meter')
        ->and($pointers)->toContain('/quantity')
        ->and($pointers)->toContain('/customer/external_id');
});

it('uses one shape for every failure the framework raises', function (string $path, int $status, string $type): void {
    getJson($path)
        ->assertStatus($status)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://metered.dev/problems/' . $type)
        ->assertJsonStructure(['type', 'title', 'status', 'detail', 'instance']);
})->with([
    'unknown route' => ['/api/v1/nothing-here', 404, 'not-found'],
    'forbidden' => ['/api/v1/test-forbidden', 403, 'forbidden'],
    'shedding load' => ['/api/v1/test-overloaded', 503, 'service-unavailable'],
]);

it('refuses a method the route does not have', function (): void {
    putJson('/api/v1/test-forbidden')
        ->assertStatus(405)
        ->assertJsonPath('type', 'https://metered.dev/problems/method-not-allowed');
});

it('never leaks an internal message when debug is off', function (): void {
    config(['app.debug' => false]);

    $response = getJson('/api/v1/test-broken');

    $response->assertStatus(500)
        ->assertJsonPath('type', 'https://metered.dev/problems/internal-error')
        ->assertJsonPath('detail', 'Something went wrong on our side. The failure has been logged.');

    // The exception carried a password. Nothing of it reaches the client.
    expect(json_encode($response->json()))->not->toContain('hunter2');
});

it('shows the internal message when somebody is already debugging', function (): void {
    config(['app.debug' => true]);

    getJson('/api/v1/test-broken')
        ->assertStatus(500)
        ->assertJsonPath('detail', 'connection string: postgres://user:hunter2@db/metered');
});
