<?php

declare(strict_types=1);

use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Metered\Tenancy\Domain\Role;
use Metered\Tenancy\Infrastructure\Eloquent\User;

use function Pest\Laravel\get;

use Tests\Support\TenantFactory;

/**
 * Signing in through the login form, with a password, and nothing standing in
 * for it. Every other panel test starts from `actingAs()`, which hands the
 * guard a user and so never asks the configured user provider for one — the
 * one path a person signing in takes.
 */
it('signs a member in through the login form and keeps them signed in', function (): void {
    $project = TenantFactory::tenant('acme');
    $member = TenantFactory::member($project->organizationId, Role::Owner, 'owner@acme.example');
    Filament::setCurrentPanel('admin');

    Livewire::test(Login::class)
        ->set('data.email', 'owner@acme.example')
        ->set('data.password', 'correct horse battery staple')
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(Auth::user())->toBeInstanceOf(User::class)
        ->and(Auth::id())->toBe($member->userId?->value);

    // The next request finds the user again from what the session stored.
    Auth::forgetGuards();

    get('/admin/projects')->assertOk()->assertSee('production');
});
