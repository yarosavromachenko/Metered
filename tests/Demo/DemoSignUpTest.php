<?php

declare(strict_types=1);

use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
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

it('lets a visitor sign in again with the password they signed up with', function (): void {
    Queue::fake();
    Filament::setCurrentPanel('admin');

    Livewire::test(RegisterTenant::class)
        ->set('data.name', 'Visitor')
        ->set('data.email', 'visitor@example.com')
        ->set('data.password', 'correct horse battery staple')
        ->set('data.passwordConfirmation', 'correct horse battery staple')
        ->set('data.organization', 'Visitor Labs')
        ->call('register')
        ->assertHasNoErrors();

    $hash = DB::table('users')->where('email', 'visitor@example.com')->value('password');

    expect(app(Hasher::class)->check('correct horse battery staple', is_string($hash) ? $hash : ''))->toBeTrue();

    Auth::logout();

    Livewire::test(Login::class)
        ->set('data.email', 'visitor@example.com')
        ->set('data.password', 'correct horse battery staple')
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(Auth::check())->toBeTrue();
});

it('tells a visitor how to look around before signing up', function (): void {
    get('/admin/login')
        ->assertOk()
        ->assertSee('Look around first')
        ->assertSee('demo@metered.test')
        ->assertSee('metered-demo');
});
