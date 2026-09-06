<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Metered\Tenancy\Presentation\Filament\Auth\RegisterTenant;

use function Pest\Laravel\get;

it('offers sign-up when the instance is a demo', function (): void {
    expect(config('metered.demo.enabled'))->toBeTrue()
        ->and(config('metered.admin.registration_page'))->toBe(RegisterTenant::class)
        ->and(Route::has('filament.admin.auth.register'))->toBeTrue();

    get('/admin/register')
        ->assertOk()
        ->assertSee('Company')
        // Said on the page rather than discovered later: there is no mail
        // infrastructure behind a reset link.
        ->assertSee('no password reset');
});

it('starts a visitor with nothing and no tenant until they sign up', function (): void {
    expect(DB::table('users')->count())->toBe(0)
        ->and(DB::table('organizations')->count())->toBe(0);

    get('/admin')->assertRedirect();
});

it('tells a visitor how to look around before signing up', function (): void {
    get('/admin/login')
        ->assertOk()
        ->assertSee('Look around first')
        ->assertSee('demo@metered.test')
        ->assertSee('metered-demo');
});
