<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Exception\InvalidMoney;
use Metered\Tenancy\Application\Authentication\ApiKeyAuthenticator;
use Metered\Tenancy\Application\Command\ProvisionedTenant;
use Metered\Tenancy\Application\Command\ProvisionTenant;
use Metered\Tenancy\Application\Command\ProvisionTenantHandler;
use Metered\Tenancy\Domain\Environment;
use Metered\Tenancy\Domain\OrganizationRepository;
use Metered\Tenancy\Domain\ProjectRepository;
use Metered\Tenancy\Domain\Scope;
use Metered\Tenancy\Domain\Slug;

function provision(string $name = 'Acme, Inc.', string $currency = 'EUR'): ProvisionedTenant
{
    return app(ProvisionTenantHandler::class)->handle(new ProvisionTenant(
        organizationName: $name,
        actor: Actor::system('test'),
        environment: Environment::Live,
        currency: $currency,
    ));
}

it('creates an organization, a project and a key that already works', function (): void {
    $tenant = provision();

    expect($tenant->organization->slug->value)->toBe('acme-inc')
        ->and($tenant->project->slug->value)->toBe('production')
        ->and($tenant->project->environment)->toBe(Environment::Live)
        ->and($tenant->apiKey->allows(Scope::UsageWrite))->toBeTrue()
        ->and($tenant->apiKey->allows(Scope::Admin))->toBeTrue();

    // The token is not a promise that something was written: it authenticates.
    $authenticated = app(ApiKeyAuthenticator::class)->authenticate($tenant->secret->reveal());

    expect($authenticated->tenant->projectId->value)->toBe($tenant->project->id->value);
});

it('records what it did in the audit log, and never the secret', function (): void {
    $tenant = provision();

    $entries = DB::table('audit_log')
        ->whereIn('action', ['organization.provisioned', 'api_key.issued'])
        ->orderBy('sequence')
        ->get();

    $payloads = $entries->pluck('payload')->implode(' ');

    expect($entries)->toHaveCount(2)
        ->and($payloads)->toContain($tenant->apiKey->prefix)
        ->and($payloads)->not->toContain(substr($tenant->secret->reveal(), -48))
        ->and($entries->pluck('actor')->unique()->all())->toBe(['test']);
});

it('gives the second organization of the same name an addressable slug', function (): void {
    $first = provision('Acme');
    $second = provision('Acme');

    expect($first->organization->slug->value)->toBe('acme')
        ->and($second->organization->slug->value)->toStartWith('acme-')
        ->and($second->organization->slug->value)->not->toBe('acme')
        ->and(app(OrganizationRepository::class)->findBySlug(Slug::fromString($second->organization->slug->value)))
        ->not->toBeNull();
});

it('leaves nothing behind when part of the work fails', function (): void {
    expect(static fn(): mixed => provision('North Wind', 'XYZ'))->toThrow(InvalidMoney::class);

    // The organization is written before the project, so a project that
    // cannot be created is exactly the case where a half tenant would survive.
    expect(app(OrganizationRepository::class)->findBySlug(Slug::fromString('north-wind')))->toBeNull()
        ->and(DB::table('projects')->count())->toBe(0)
        ->and(DB::table('api_keys')->count())->toBe(0);
});

it('opens the project under the organization it just created', function (): void {
    $tenant = provision();

    $projects = app(ProjectRepository::class)->listForOrganization($tenant->organization->id);

    expect($projects)->toHaveCount(1)
        ->and($projects[0]->id->value)->toBe($tenant->project->id->value);
});
