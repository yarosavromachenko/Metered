<?php

declare(strict_types=1);

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Domain\Project;
use Metered\Tenancy\Domain\Role;
use Metered\Tenancy\Infrastructure\Eloquent\User;
use Metered\Tenancy\Presentation\Filament\PanelScope;
use Metered\Tenancy\Presentation\Filament\Resources\ApiKeys\ApiKeyResource;

use function Pest\Laravel\actingAs;

use Tests\Support\TenantFactory;

/**
 * The panel is exercised over HTTP rather than through Livewire's test
 * harness. "Tenant A cannot see tenant B's data through any admin screen" is a
 * claim about what the screen renders, and a request through the real routes
 * is the only thing that checks all of it: the scope, the query, the policy
 * and the page.
 *
 * @return array{acme: array{project: Project, user: User}, rival: array{project: Project, user: User}}
 */
function twoTenants(): array
{
    $acme = TenantFactory::tenant('acme');
    $rival = TenantFactory::tenant('north-wind');

    return [
        'acme' => [
            'project' => $acme,
            'user' => userOf(TenantFactory::member($acme->organizationId, Role::Owner, 'owner@acme.example')->userId),
        ],
        'rival' => [
            'project' => $rival,
            'user' => userOf(TenantFactory::member($rival->organizationId, Role::Owner, 'owner@north-wind.example')->userId),
        ],
    ];
}

function userOf(?Uuid $id): User
{
    $user = User::query()->find($id?->value);

    return $user instanceof User ? $user : throw new RuntimeException('No such user.');
}

it('lets a member sign in and land on their own organization', function (): void {
    $tenants = twoTenants();

    actingAs($tenants['acme']['user'])
        ->get('/admin/projects')
        ->assertOk()
        ->assertSee('production');
});

it('keeps someone who belongs nowhere out of the panel entirely', function (): void {
    TenantFactory::tenant('acme');

    $stranger = new User();
    $stranger->id = '01924b7c-0000-7000-8000-0000000000f1';
    $stranger->name = 'Stranger';
    $stranger->email = 'stranger@example.com';
    $stranger->password = 'irrelevant';
    $stranger->save();

    actingAs($stranger)->get('/admin/projects')->assertForbidden();
});

it('shows a tenant their own projects and never the other tenant\'s', function (): void {
    $tenants = twoTenants();
    $mine = TenantFactory::project($tenants['acme']['project']->organizationId, 'sandbox');
    $theirs = TenantFactory::project($tenants['rival']['project']->organizationId, 'their-sandbox');

    actingAs($tenants['acme']['user'])
        ->get('/admin/projects')
        ->assertOk()
        ->assertSee($mine->slug->value)
        ->assertDontSee($theirs->slug->value);
});

it('shows a tenant their own keys and never the other tenant\'s', function (): void {
    $tenants = twoTenants();
    TenantFactory::apiKey($tenants['acme']['project'], name: 'Our ingestion key');
    ['key' => $theirs] = TenantFactory::apiKey($tenants['rival']['project'], name: 'Their ingestion key');

    actingAs($tenants['acme']['user'])
        ->get('/admin/api-keys')
        ->assertOk()
        ->assertSee('Our ingestion key')
        ->assertDontSee('Their ingestion key')
        ->assertDontSee($theirs->prefix);
});

it('shows a tenant their own members and never the other tenant\'s', function (): void {
    $tenants = twoTenants();

    actingAs($tenants['acme']['user'])
        ->get('/admin/members')
        ->assertOk()
        ->assertSee('owner@acme.example')
        ->assertDontSee('owner@north-wind.example');
});

it('cannot reach another tenant\'s key even by naming its id', function (): void {
    $tenants = twoTenants();
    ['key' => $theirs] = TenantFactory::apiKey($tenants['rival']['project']);

    actingAs($tenants['acme']['user']);
    withPanelSession();

    // Every action on that screen resolves its record through this query.
    // There is no id that reaches out of the scope, which is why the row
    // action refuses a record it was handed directly.
    $reachable = ApiKeyResource::getEloquentQuery()->find($theirs->id->value);

    expect($reachable)->toBeNull();
});

it('offers the key actions only to a role that may use them', function (Role $role, bool $expected): void {
    $project = TenantFactory::tenant('acme');
    $member = TenantFactory::member($project->organizationId, $role, $role->value . '@acme.example');
    TenantFactory::apiKey($project, name: 'Existing key');

    $response = actingAs(userOf($member->userId))->get('/admin/api-keys')->assertOk();

    $expected
        ? $response->assertSee('Issue key')->assertSee('Revoke')
        : $response->assertDontSee('Issue key')->assertDontSee('Revoke');
})->with([
    [Role::Owner, true],
    [Role::Admin, false],
    [Role::BillingOperator, false],
    [Role::Viewer, false],
]);

it('refuses to point the scope at a project the person cannot reach', function (): void {
    $tenants = twoTenants();

    actingAs($tenants['acme']['user']);
    withPanelSession();

    $scope = app(PanelScope::class);

    expect($scope->switchTo($tenants['rival']['project']->id->value))->toBeFalse()
        // Refused, and the scope it was holding is untouched.
        ->and($scope->tenant()?->projectId->value)->toBe($tenants['acme']['project']->id->value);
});

it('moves the scope to another project of an organization the person belongs to', function (): void {
    $tenants = twoTenants();
    $sandbox = TenantFactory::project($tenants['acme']['project']->organizationId, 'sandbox');

    actingAs($tenants['acme']['user']);
    withPanelSession();

    $scope = app(PanelScope::class);

    expect($scope->switchTo($sandbox->id->value))->toBeTrue()
        ->and($scope->tenant()?->projectId->value)->toBe($sandbox->id->value);
});

/**
 * A request built outside the HTTP kernel has no session; the panel keeps its
 * scope in one, so these tests attach the store the session middleware would.
 */
function withPanelSession(): void
{
    app('request')->setLaravelSession(app('session.store'));
}
