<?php

declare(strict_types=1);

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Metered\Tenancy\Domain\Role;
use Tests\Support\TenantFactory;

it('adds a person with a role and the password they were given', function (): void {
    $organization = TenantFactory::organization('northwind');

    expect(Artisan::call('org:member', ['organization' => 'northwind', 'email' => 'demo@metered.test', '--password' => 'metered-demo']))->toBe(0)
        ->and(Artisan::output())->toContain('demo@metered.test can sign in as viewer')
        // A password the operator chose is not echoed back.
        ->and(str_contains(Artisan::output(), 'metered-demo'))->toBeFalse();

    $user = DB::table('users')->where('email', 'demo@metered.test')->first(['id', 'name', 'password']);
    $hash = is_object($user) && is_string($user->password) ? $user->password : '';

    expect(is_object($user) ? $user->name : null)->toBe('Demo')
        ->and(app(Hasher::class)->check('metered-demo', $hash))->toBeTrue()
        ->and(DB::table('organization_members')->where('organization_id', $organization->id->value)->value('role'))->toBe('viewer')
        ->and(DB::table('audit_log')->where('action', 'member.added')->where('subject_id', $organization->id->value)->exists())->toBeTrue();
});

it('generates a password when given none, and prints it once', function (): void {
    TenantFactory::organization('northwind');

    expect(Artisan::call('org:member', ['organization' => 'northwind', 'email' => 'ops@example.com', '--role' => 'admin', '--name' => 'Ops']))->toBe(0);

    preg_match('/^([0-9a-f]{18})$/m', Artisan::output(), $printed);
    $hash = DB::table('users')->where('email', 'ops@example.com')->value('password');

    expect($printed[1] ?? null)->not->toBeNull()
        ->and(app(Hasher::class)->check($printed[1] ?? '', is_string($hash) ? $hash : ''))->toBeTrue();
});

it('refuses an organization, a role or an address it cannot use', function (array $arguments, string $message): void {
    TenantFactory::member(TenantFactory::organization('northwind')->id, Role::Owner, 'taken@example.com');

    expect(Artisan::call('org:member', $arguments))->toBe(2)
        ->and(Artisan::output())->toContain($message);
})->with([
    'no such organization' => [['organization' => 'nowhere', 'email' => 'a@example.com'], 'No organization "nowhere"'],
    'no such role' => [['organization' => 'northwind', 'email' => 'a@example.com', '--role' => 'emperor'], 'The role must be'],
    'an address already registered' => [['organization' => 'northwind', 'email' => 'taken@example.com'], 'taken@example.com'],
]);

it('creates no demo organization outside demo mode', function (): void {
    expect(Artisan::call('org:create', ['name' => 'Showcase', '--demo' => true]))->toBe(2)
        ->and(Artisan::output())->toContain('only in demo mode')
        ->and(DB::table('organizations')->count())->toBe(0);
});
