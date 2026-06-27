<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Tenancy\Application\Authentication\ApiKeyAuthenticator;
use Metered\Tenancy\Application\Authentication\AuthenticationFailed;
use Metered\Tenancy\Domain\ApiKeyRepository;
use Metered\Tenancy\Domain\Project;
use Metered\Tenancy\Domain\ProjectRepository;
use Metered\Tenancy\Domain\Role;
use Metered\Tenancy\Domain\Scope;
use Metered\Tenancy\Infrastructure\Eloquent\User;
use Metered\Tenancy\Presentation\Filament\Actions\CreateProjectAction;
use Metered\Tenancy\Presentation\Filament\Actions\IssueKeyAction;
use Metered\Tenancy\Presentation\Filament\Actions\RevokeKeyAction;

use function Pest\Laravel\actingAs;

use Tests\Support\TenantFactory;

/**
 * The panel's write paths, called the way the action closures call them.
 *
 * They are separate classes rather than closures precisely so that this file
 * can exist: a closure that only runs inside a browser is a closure nothing
 * checks, and the first version of one of these had a bug in its opening line.
 */
function signedInOwner(string $organizationSlug = 'acme', Role $role = Role::Owner): Project
{
    $project = TenantFactory::tenant($organizationSlug);
    $member = TenantFactory::member($project->organizationId, $role, $role->value . '@' . $organizationSlug . '.example');

    $user = User::query()->find($member->userId?->value);
    actingAs($user instanceof User ? $user : throw new RuntimeException('No user.'));

    // A request built outside the HTTP kernel has no session, and the panel
    // scope lives in one.
    app('request')->setLaravelSession(app('session.store'));

    return $project;
}

it('issues a key from the panel that authenticates straight away', function (): void {
    $project = signedInOwner();

    IssueKeyAction::run(['name' => 'CI ingestion', 'scopes' => [Scope::UsageWrite->value]]);

    $keys = app(ApiKeyRepository::class)->listFor($project->tenant());

    expect($keys)->toHaveCount(1)
        ->and($keys[0]->name)->toBe('CI ingestion')
        ->and($keys[0]->allows(Scope::UsageWrite))->toBeTrue()
        ->and($keys[0]->allows(Scope::Admin))->toBeFalse()
        // The secret was shown in a notification and stored nowhere.
        ->and(DB::table('api_keys')->where('id', $keys[0]->id->value)->value('secret_hash'))
        ->toMatch('/^[0-9a-f]{64}$/');
});

it('drops anything in the scope list that is not a scope', function (): void {
    $project = signedInOwner();

    IssueKeyAction::run(['name' => 'Odd input', 'scopes' => ['usage:write', 'root', 42, null]]);

    expect(app(ApiKeyRepository::class)->listFor($project->tenant())[0]->scopes)
        ->toBe([Scope::UsageWrite]);
});

it('refuses to issue a key for a role that may not manage the tenant', function (): void {
    $project = signedInOwner('acme', Role::Admin);

    IssueKeyAction::run(['name' => 'Not mine', 'scopes' => [Scope::UsageWrite->value]]);

    expect(app(ApiKeyRepository::class)->listFor($project->tenant()))->toBe([]);
});

it('revokes a key from the panel and the next request with it fails', function (): void {
    $project = signedInOwner();
    ['key' => $key, 'secret' => $secret] = TenantFactory::apiKey($project);

    RevokeKeyAction::run($key->id->value);

    expect(static fn(): mixed => app(ApiKeyAuthenticator::class)->authenticate($secret->reveal()))
        ->toThrow(AuthenticationFailed::class, 'was revoked');
});

it('leaves another tenant\'s key alone when handed its id', function (): void {
    signedInOwner('acme');
    $rival = TenantFactory::tenant('north-wind');
    ['key' => $theirs, 'secret' => $secret] = TenantFactory::apiKey($rival);

    RevokeKeyAction::run($theirs->id->value);

    expect(app(ApiKeyAuthenticator::class)->authenticate($secret->reveal())->revokedAt)->toBeNull();
});

it('ignores an id that is not an id at all', function (): void {
    $project = signedInOwner();
    ['key' => $key, 'secret' => $secret] = TenantFactory::apiKey($project);

    RevokeKeyAction::run('not-a-uuid');
    RevokeKeyAction::run(app(IdentifierGenerator::class)->generate()->value);

    expect(app(ApiKeyAuthenticator::class)->authenticate($secret->reveal())->id->value)
        ->toBe($key->id->value);
});

it('creates a project from the panel with the currency it was given', function (): void {
    $project = signedInOwner();

    CreateProjectAction::run(['name' => 'Sandbox', 'environment' => 'live', 'currency' => 'usd']);

    $projects = app(ProjectRepository::class)->listForOrganization($project->organizationId);
    $sandbox = array_values(array_filter(
        $projects,
        static fn(Project $candidate): bool => $candidate->slug->value === 'sandbox',
    ));

    expect($sandbox)->toHaveCount(1)
        ->and($sandbox[0]->environment->value)->toBe('live')
        ->and($sandbox[0]->currency)->toBe('USD');
});

it('reports a refused project instead of creating a second one', function (): void {
    $project = signedInOwner();

    // "production" is the project the tenant was provisioned with.
    CreateProjectAction::run(['name' => 'Production', 'environment' => 'test', 'currency' => 'EUR']);

    expect(app(ProjectRepository::class)->listForOrganization($project->organizationId))
        ->toHaveCount(1);
});

it('reports a currency the domain refuses rather than failing the request', function (): void {
    $project = signedInOwner();

    CreateProjectAction::run(['name' => 'Sandbox', 'environment' => 'test', 'currency' => 'XYZ']);

    expect(app(ProjectRepository::class)->listForOrganization($project->organizationId))
        ->toHaveCount(1);
});
