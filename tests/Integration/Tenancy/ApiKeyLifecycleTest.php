<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Access\PermissionDenied;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Application\Authentication\ApiKeyAuthenticator;
use Metered\Tenancy\Application\Authentication\AuthenticationFailed;
use Metered\Tenancy\Application\Command\IssueApiKey;
use Metered\Tenancy\Application\Command\IssueApiKeyHandler;
use Metered\Tenancy\Application\Command\RevokeApiKey;
use Metered\Tenancy\Application\Command\RevokeApiKeyHandler;
use Metered\Tenancy\Application\Command\TenantNotFound;
use Metered\Tenancy\Domain\Role;
use Metered\Tenancy\Domain\Scope;
use Tests\Support\TenantFactory;

it('issues a key for a project and returns the secret exactly once', function (): void {
    $project = TenantFactory::tenant();
    $owner = TenantFactory::member($project->organizationId);

    $issued = app(IssueApiKeyHandler::class)->handle(new IssueApiKey(
        $project->tenant(),
        'CI ingestion',
        [Scope::UsageWrite],
        $owner,
    ));

    expect($issued->key->name)->toBe('CI ingestion')
        // Taken from the project, never from the caller: the shape of the
        // token has to agree with the project it belongs to.
        ->and($issued->secret->environment())->toBe($project->environment)
        ->and(app(ApiKeyAuthenticator::class)->authenticate($issued->secret->reveal())->id->value)
        ->toBe($issued->key->id->value);
});

it('refuses a key to every role but the owner', function (Role $role): void {
    $project = TenantFactory::tenant();
    $member = TenantFactory::member($project->organizationId, $role);

    $issue = static fn(): mixed => app(IssueApiKeyHandler::class)->handle(new IssueApiKey(
        $project->tenant(),
        'Not mine to issue',
        [Scope::UsageWrite],
        $member,
    ));

    // A key reaches everything in the project, so issuing one is the same
    // authority as adding a member: the owner's (ADR-0017).
    $role === Role::Owner
        ? expect($issue())->not->toBeNull()
        : expect($issue)->toThrow(PermissionDenied::class, 'tenant.manage');
})->with(Role::cases());

it('refuses a key to a member of a different organization', function (): void {
    $acme = TenantFactory::tenant('acme');
    $rival = TenantFactory::tenant('north-wind');
    $rivalOwner = TenantFactory::member($rival->organizationId, Role::Owner, 'owner@north-wind.example');

    expect(static fn(): mixed => app(IssueApiKeyHandler::class)->handle(new IssueApiKey(
        $acme->tenant(),
        'Borrowed',
        [Scope::Admin],
        $rivalOwner,
    )))->toThrow(PermissionDenied::class);
});

it('will not issue a key for a project of another organization', function (): void {
    $acme = TenantFactory::tenant('acme');
    $rival = TenantFactory::tenant('north-wind');

    expect(static fn(): mixed => app(IssueApiKeyHandler::class)->handle(new IssueApiKey(
        // Acme's project id, under the rival's organization.
        new TenantContext($rival->organizationId, $acme->id),
        'Borrowed',
        [Scope::Admin],
        Actor::system('test'),
    )))->toThrow(TenantNotFound::class);
});

it('revokes a key and the next request with it is refused', function (): void {
    $project = TenantFactory::tenant();
    ['key' => $key, 'secret' => $secret] = TenantFactory::apiKey($project);

    app(RevokeApiKeyHandler::class)->handle(new RevokeApiKey(
        $project->tenant(),
        $key->id,
        TenantFactory::member($project->organizationId),
    ));

    expect(static fn(): mixed => app(ApiKeyAuthenticator::class)->authenticate($secret->reveal()))
        ->toThrow(AuthenticationFailed::class, 'was revoked');
});

it('refuses a revocation from a role that may not manage the tenant', function (): void {
    $project = TenantFactory::tenant();
    ['key' => $key, 'secret' => $secret] = TenantFactory::apiKey($project);
    $admin = TenantFactory::member($project->organizationId, Role::Admin);

    expect(static fn(): mixed => app(RevokeApiKeyHandler::class)->handle(new RevokeApiKey(
        $project->tenant(),
        $key->id,
        $admin,
    )))->toThrow(PermissionDenied::class);

    // And the key still works, which is the part that matters.
    expect(app(ApiKeyAuthenticator::class)->authenticate($secret->reveal())->id->value)
        ->toBe($key->id->value);
});

it('cannot revoke a key belonging to someone else', function (): void {
    $acme = TenantFactory::tenant('acme');
    $rival = TenantFactory::tenant('north-wind');
    ['key' => $key, 'secret' => $secret] = TenantFactory::apiKey($acme);

    expect(static fn(): mixed => app(RevokeApiKeyHandler::class)->handle(new RevokeApiKey(
        $rival->tenant(),
        $key->id,
        TenantFactory::member($rival->organizationId, Role::Owner, 'owner@north-wind.example'),
    )))->toThrow(TenantNotFound::class);

    expect(app(ApiKeyAuthenticator::class)->authenticate($secret->reveal())->id->value)
        ->toBe($key->id->value);
});

it('refuses to revoke a key that does not exist', function (): void {
    $project = TenantFactory::tenant();

    expect(static fn(): mixed => app(RevokeApiKeyHandler::class)->handle(new RevokeApiKey(
        $project->tenant(),
        app(IdentifierGenerator::class)->generate(),
        TenantFactory::member($project->organizationId),
    )))->toThrow(TenantNotFound::class);
});

it('writes an audit entry for both ends of a key\'s life', function (): void {
    $project = TenantFactory::tenant();
    $owner = TenantFactory::member($project->organizationId, Role::Owner, 'owner@acme.example');

    $issued = app(IssueApiKeyHandler::class)->handle(new IssueApiKey(
        $project->tenant(),
        'Short lived',
        [Scope::Admin],
        $owner,
    ));

    app(RevokeApiKeyHandler::class)->handle(new RevokeApiKey(
        $project->tenant(),
        $issued->key->id,
        $owner,
    ));

    $entries = DB::table('audit_log')
        ->where('subject_id', $issued->key->id->value)
        ->orderBy('sequence')
        ->get();

    expect($entries->pluck('action')->all())->toBe(['api_key.issued', 'api_key.revoked'])
        ->and($entries->pluck('actor')->unique()->all())->toBe(['user:owner@acme.example'])
        ->and($entries->pluck('payload')->implode(' '))
        ->toContain($issued->key->prefix)
        ->and($entries->pluck('payload')->implode(' '))
        ->not->toContain(substr($issued->secret->reveal(), -48));
});
