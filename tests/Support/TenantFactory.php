<?php

declare(strict_types=1);

namespace Tests\Support;

use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Tenancy\Domain\ApiKey;
use Metered\Tenancy\Domain\ApiKeyRepository;
use Metered\Tenancy\Domain\ApiKeySecret;
use Metered\Tenancy\Domain\Environment;
use Metered\Tenancy\Domain\Organization;
use Metered\Tenancy\Domain\OrganizationRepository;
use Metered\Tenancy\Domain\Project;
use Metered\Tenancy\Domain\ProjectRepository;
use Metered\Tenancy\Domain\Scope;
use Metered\Tenancy\Domain\Slug;
use Psr\Clock\ClockInterface;

/**
 * Persisted tenants for tests that need something to be scoped to.
 *
 * Deliberately built on the repositories rather than on raw inserts: a fixture
 * that bypasses the mapping would keep passing after the mapping broke.
 */
final class TenantFactory
{
    public static function organization(string $slug = 'acme'): Organization
    {
        $organization = Organization::register(
            app(IdentifierGenerator::class)->generate(),
            ucfirst($slug),
            Slug::fromString($slug),
            app(ClockInterface::class)->now(),
        );

        app(OrganizationRepository::class)->save($organization);

        return $organization;
    }

    public static function project(
        Organization $organization,
        string $slug = 'production',
        Environment $environment = Environment::Test,
        string $currency = 'EUR',
    ): Project {
        $project = Project::open(
            app(IdentifierGenerator::class)->generate(),
            $organization->id,
            ucfirst($slug),
            Slug::fromString($slug),
            $environment,
            $currency,
            app(ClockInterface::class)->now(),
        );

        app(ProjectRepository::class)->save($project);

        return $project;
    }

    /**
     * A whole tenant in one call, for tests that care about isolation rather
     * than about how a tenant is assembled.
     */
    public static function tenant(string $slug = 'acme'): Project
    {
        return self::project(self::organization($slug));
    }

    /**
     * @param  list<Scope>  $scopes
     * @return array{key: ApiKey, secret: ApiKeySecret}
     */
    public static function apiKey(
        Project $project,
        array $scopes = [Scope::UsageWrite],
        string $name = 'Ingestion',
    ): array {
        $secret = ApiKeySecret::generate($project->environment);

        $key = ApiKey::issue(
            app(IdentifierGenerator::class)->generate(),
            $project->tenant(),
            $name,
            $secret,
            $scopes,
            app(ClockInterface::class)->now(),
        );

        app(ApiKeyRepository::class)->save($key);

        return ['key' => $key, 'secret' => $secret];
    }
}
