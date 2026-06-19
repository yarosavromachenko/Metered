<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Domain\Environment;
use Metered\Tenancy\Domain\OrganizationRepository;
use Metered\Tenancy\Domain\Project;
use Metered\Tenancy\Domain\ProjectRepository;
use Metered\Tenancy\Domain\Slug;
use Tests\Support\TenantFactory;

it('stores an organization and reads it back as the same organization', function (): void {
    $organization = TenantFactory::organization('acme');
    $found = app(OrganizationRepository::class)->find($organization->id);

    expect($found?->id->value)->toBe($organization->id->value)
        ->and($found?->slug->value)->toBe('acme')
        ->and($found?->createdAt->getTimestamp())->toBe($organization->createdAt->getTimestamp());
});

it('finds an organization by the slug it is addressed with', function (): void {
    TenantFactory::organization('north-wind');

    expect(app(OrganizationRepository::class)->findBySlug(Slug::fromString('north-wind'))?->slug->value)
        ->toBe('north-wind')
        ->and(app(OrganizationRepository::class)->findBySlug(Slug::fromString('nobody')))
        ->toBeNull();
});

it('refuses two organizations with the same slug', function (): void {
    TenantFactory::organization('acme');

    expect(static fn(): mixed => TenantFactory::organization('acme'))
        ->toThrow(QueryException::class);
});

it('fetches a project by the pair that identifies it, not by its id alone', function (): void {
    $acme = TenantFactory::organization('acme');
    $rival = TenantFactory::organization('north-wind');
    $project = TenantFactory::project($acme);

    $projects = app(ProjectRepository::class);

    expect($projects->find($project->tenant())?->id->value)->toBe($project->id->value)
        // The same project id, asked for under the wrong organization. This is
        // the shape of a tenant leak, and the answer is nothing at all.
        ->and($projects->find(new TenantContext($rival->id, $project->id)))->toBeNull();
});

it('keeps project slugs unique inside an organization but not across them', function (): void {
    $acme = TenantFactory::organization('acme');
    $rival = TenantFactory::organization('north-wind');

    TenantFactory::project($acme, 'production');
    TenantFactory::project($rival, 'production');

    expect(app(ProjectRepository::class)->findBySlug($acme->id, Slug::fromString('production'))?->organizationId->value)
        ->toBe($acme->id->value)
        ->and(static fn(): mixed => TenantFactory::project($acme, 'production'))
        ->toThrow(QueryException::class);
});

it('lists the projects of one organization and only those', function (): void {
    $acme = TenantFactory::organization('acme');
    $rival = TenantFactory::organization('north-wind');

    TenantFactory::project($acme, 'production', Environment::Live);
    TenantFactory::project($acme, 'sandbox');
    TenantFactory::project($rival, 'production');

    $slugs = array_map(
        static fn(Project $project): string => $project->slug->value,
        app(ProjectRepository::class)->listForOrganization($acme->id),
    );

    expect($slugs)->toBe(['production', 'sandbox']);
});

it('refuses a currency the column was not meant to hold', function (): void {
    $organization = TenantFactory::organization('acme');

    expect(static fn(): bool => DB::table('projects')->insert([
        'id' => app(IdentifierGenerator::class)->generate()->value,
        'organization_id' => $organization->id->value,
        'name' => 'Production',
        'slug' => 'raw',
        'environment' => 'live',
        'currency' => 'eur',
        'created_at' => now(),
    ]))->toThrow(QueryException::class, 'projects_currency_check');
});

it('refuses an environment that is neither live nor test', function (): void {
    $organization = TenantFactory::organization('acme');

    expect(static fn(): bool => DB::table('projects')->insert([
        'id' => app(IdentifierGenerator::class)->generate()->value,
        'organization_id' => $organization->id->value,
        'name' => 'Staging',
        'slug' => 'staging',
        'environment' => 'prod',
        'currency' => 'EUR',
        'created_at' => now(),
    ]))->toThrow(QueryException::class, 'projects_environment_check');
});
