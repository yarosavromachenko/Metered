<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

use function Pest\Laravel\get;

/**
 * The suite runs with demo mode off, which is what a real installation looks
 * like. The sign-up screen should then not exist at all — not merely be hidden
 * behind a check somebody could forget.
 */
it('has no sign-up route when the instance is not a demo', function (): void {
    expect(config('metered.demo.enabled'))->toBeFalse()
        ->and(config('metered.admin.registration_page'))->toBeNull()
        ->and(Route::has('filament.admin.auth.register'))->toBeFalse();

    get('/admin/register')->assertNotFound();
});

it('offers no showcase account on a sign-in page that is not a demo\'s', function (): void {
    get('/admin/login')
        ->assertOk()
        ->assertDontSee('Look around first')
        ->assertDontSee('metered-demo');
});
