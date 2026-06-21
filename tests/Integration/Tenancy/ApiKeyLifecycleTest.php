<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Application\Authentication\ApiKeyAuthenticator;
use Metered\Tenancy\Application\Authentication\AuthenticationFailed;
use Metered\Tenancy\Application\Command\IssueApiKey;
use Metered\Tenancy\Application\Command\IssueApiKeyHandler;
use Metered\Tenancy\Application\Command\RevokeApiKey;
use Metered\Tenancy\Application\Command\RevokeApiKeyHandler;
use Metered\Tenancy\Application\Command\TenantNotFound;
use Metered\Tenancy\Domain\Scope;
use Tests\Support\TenantFactory;

it('issues a key for a project and returns the secret exactly once', function (): void {
    $project = TenantFactory::tenant();

    $issued = app(IssueApiKeyHandler::class)->handle(new IssueApiKey(
        $project->tenant(),
        'CI ingestion',
        [Scope::UsageWrite],
        'user:someone@example.com',
    ));

    expect($issued->key->name)->toBe('CI ingestion')
        // Taken from the project, never from the caller: the shape of the
        // token has to agree with the project it belongs to.
        ->and($issued->secret->environment())->toBe($project->environment)
        ->and(app(ApiKeyAuthenticator::class)->authenticate($issued->secret->reveal())->id->value)
        ->toBe($issued->key->id->value);
});

it('will not issue a key for a project of another organization', function (): void {
    $acme = TenantFactory::tenant('acme');
    $rival = TenantFactory::tenant('north-wind');

    expect(static fn(): mixed => app(IssueApiKeyHandler::class)->handle(new IssueApiKey(
        // Acme's project id, under the rival's organization.
        new TenantContext($rival->organizationId, $acme->id),
        'Borrowed',
        [Scope::Admin],
        'user:intruder@example.com',
    )))->toThrow(TenantNotFound::class);
});

it('revokes a key and the next request with it is refused', function (): void {
    $project = TenantFactory::tenant();
    ['key' => $key, 'secret' => $secret] = TenantFactory::apiKey($project);

    app(RevokeApiKeyHandler::class)->handle(new RevokeApiKey(
        $project->tenant(),
        $key->id,
        'user:someone@example.com',
    ));

    expect(static fn(): mixed => app(ApiKeyAuthenticator::class)->authenticate($secret->reveal()))
        ->toThrow(AuthenticationFailed::class, 'was revoked');
});

it('cannot revoke a key belonging to someone else', function (): void {
    $acme = TenantFactory::tenant('acme');
    $rival = TenantFactory::tenant('north-wind');
    ['key' => $key, 'secret' => $secret] = TenantFactory::apiKey($acme);

    expect(static fn(): mixed => app(RevokeApiKeyHandler::class)->handle(new RevokeApiKey(
        $rival->tenant(),
        $key->id,
        'user:intruder@example.com',
    )))->toThrow(TenantNotFound::class);

    // And the key still works, which is the part that matters.
    expect(app(ApiKeyAuthenticator::class)->authenticate($secret->reveal())->id->value)
        ->toBe($key->id->value);
});

it('refuses to revoke a key that does not exist', function (): void {
    $project = TenantFactory::tenant();

    expect(static fn(): mixed => app(RevokeApiKeyHandler::class)->handle(new RevokeApiKey(
        $project->tenant(),
        app(IdentifierGenerator::class)->generate(),
        'user:someone@example.com',
    )))->toThrow(TenantNotFound::class);
});

it('writes an audit entry for both ends of a key\'s life', function (): void {
    $project = TenantFactory::tenant();

    $issued = app(IssueApiKeyHandler::class)->handle(new IssueApiKey(
        $project->tenant(),
        'Short lived',
        [Scope::Admin],
        'user:someone@example.com',
    ));

    app(RevokeApiKeyHandler::class)->handle(new RevokeApiKey(
        $project->tenant(),
        $issued->key->id,
        'user:someone.else@example.com',
    ));

    $entries = DB::table('audit_log')
        ->where('subject_id', $issued->key->id->value)
        ->orderBy('sequence')
        ->get();

    expect($entries->pluck('action')->all())->toBe(['api_key.issued', 'api_key.revoked'])
        ->and($entries->pluck('actor')->all())
        ->toBe(['user:someone@example.com', 'user:someone.else@example.com'])
        ->and($entries->pluck('payload')->implode(' '))
        ->toContain($issued->key->prefix)
        ->and($entries->pluck('payload')->implode(' '))
        ->not->toContain(substr($issued->secret->reveal(), -48));
});
