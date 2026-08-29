<?php

declare(strict_types=1);

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;
use Metered\Tenancy\Application\Command\EmailAlreadyRegistered;
use Metered\Tenancy\Application\Command\RegisterDemoTenant;
use Metered\Tenancy\Application\Command\RegisterDemoTenantHandler;
use Metered\Tenancy\Application\Command\RegisteredDemoTenant;
use Metered\Tenancy\Domain\MembershipRepository;
use Metered\Tenancy\Domain\Role;

function register(string $email = 'someone@example.com', string $organization = 'Acme, Inc.'): RegisteredDemoTenant
{
    return app(RegisterDemoTenantHandler::class)->handle(new RegisterDemoTenant(
        name: 'Someone',
        email: $email,
        password: 'correct horse battery staple',
        organizationName: $organization,
    ));
}

it('turns one form into a person who owns a working tenant', function (): void {
    $registered = register();

    $membership = app(MembershipRepository::class)
        ->find($registered->tenant->organization->id, $registered->userId);

    expect($membership?->role)->toBe(Role::Owner)
        ->and($registered->tenant->organization->slug->value)->toBe('acme-inc')
        ->and($registered->tenant->project->slug->value)->toBe('production')
        ->and($registered->tenant->secret->reveal())->toStartWith('mk_test_');
});

it('creates the tenant as a demo, which is what lets it expire', function (): void {
    $registered = register();

    expect($registered->tenant->organization->demo)->toBeTrue()
        ->and(DB::table('organizations')->where('id', $registered->tenant->organization->id->value)->value('demo'))->toBeTrue();
});

it('stores the password only as a hash', function (): void {
    $registered = register();

    $stored = DB::table('users')->where('id', $registered->userId->value)->value('password');
    $hash = is_string($stored) ? $stored : '';

    expect($hash)->not->toBe('')
        ->and($hash)->not->toContain('correct horse')
        ->and(app(Hasher::class)->check('correct horse battery staple', $hash))->toBeTrue();
});

it('normalises the email it registers', function (): void {
    $registered = register('  Someone@Example.COM ');

    expect(DB::table('users')->where('id', $registered->userId->value)->value('email'))
        ->toBe('someone@example.com');
});

it('refuses a second account for the same address, whatever its case', function (): void {
    register('someone@example.com');

    expect(static fn(): mixed => register('SOMEONE@example.com'))
        ->toThrow(EmailAlreadyRegistered::class);

    expect(DB::table('users')->count())->toBe(1)
        // And no half tenant behind the refused account.
        ->and(DB::table('organizations')->count())->toBe(1);
});

it('lets two strangers pick the same company name', function (): void {
    $first = register('first@example.com', 'Acme');
    $second = register('second@example.com', 'Acme');

    expect($first->tenant->organization->slug->value)->toBe('acme')
        ->and($second->tenant->organization->slug->value)->not->toBe('acme')
        ->and(app(MembershipRepository::class)->forUser($second->userId))->toHaveCount(1);
});

it('records the sign-up in the audit log under the person who signed up', function (): void {
    $registered = register();

    $entry = DB::table('audit_log')->where('action', 'user.registered')->first();
    $payload = is_string($entry?->payload) ? $entry->payload : '';

    expect($entry?->actor)->toBe('user:someone@example.com')
        ->and($entry?->subject_id)->toBe($registered->userId->value)
        // jsonb, so PostgreSQL decides the spelling; decode rather than
        // matching the text it chose to store.
        ->and(json_decode($payload, true))
        ->toMatchArray(['role' => 'owner', 'demo' => true]);
});
