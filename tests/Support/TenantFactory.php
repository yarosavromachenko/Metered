<?php

declare(strict_types=1);

namespace Tests\Support;

use DateTimeImmutable;
use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Application\Identity\UserAccounts;
use Metered\Tenancy\Domain\ApiKey;
use Metered\Tenancy\Domain\ApiKeyRepository;
use Metered\Tenancy\Domain\ApiKeySecret;
use Metered\Tenancy\Domain\Environment;
use Metered\Tenancy\Domain\Membership;
use Metered\Tenancy\Domain\MembershipRepository;
use Metered\Tenancy\Domain\Organization;
use Metered\Tenancy\Domain\OrganizationRepository;
use Metered\Tenancy\Domain\Project;
use Metered\Tenancy\Domain\ProjectRepository;
use Metered\Tenancy\Domain\Role;
use Metered\Tenancy\Domain\Scope;
use Metered\Tenancy\Domain\Slug;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Persisted tenants for tests that need something to be scoped to.
 *
 * Deliberately built on the repositories rather than on raw inserts: a fixture
 * that bypasses the mapping would keep passing after the mapping broke.
 */
final class TenantFactory
{
    public static function organization(string $slug = 'acme', bool $demo = false): Organization
    {
        $organization = Organization::register(
            app(IdentifierGenerator::class)->generate(),
            ucfirst($slug),
            Slug::fromString($slug),
            app(ClockInterface::class)->now(),
            $demo,
        );

        app(OrganizationRepository::class)->save($organization);

        return $organization;
    }

    public static function project(
        Organization|Uuid $organization,
        string $slug = 'production',
        Environment $environment = Environment::Test,
        string $currency = 'EUR',
    ): Project {
        $project = Project::open(
            app(IdentifierGenerator::class)->generate(),
            $organization instanceof Organization ? $organization->id : $organization,
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
    public static function tenant(string $slug = 'acme', bool $demo = false): Project
    {
        return self::project(self::organization($slug, $demo));
    }

    /**
     * A person with a role in an organization, as the actor commands take.
     */
    public static function member(Uuid $organizationId, Role $role = Role::Owner, ?string $email = null): Actor
    {
        $email ??= $role->value . '@example.com';

        $userId = app(UserAccounts::class)->register(
            ucfirst($role->value),
            $email,
            'correct horse battery staple',
            app(ClockInterface::class)->now(),
        );

        app(MembershipRepository::class)->save(new Membership(
            app(IdentifierGenerator::class)->generate(),
            $organizationId,
            $userId,
            $role,
            app(ClockInterface::class)->now(),
        ));

        return Actor::user($userId, $email);
    }

    /**
     * The user id behind an actor, for tests that need to look it up.
     */
    public static function userIdOf(Actor $actor): Uuid
    {
        return $actor->userId ?? throw new RuntimeException('That actor is the system, not a person.');
    }

    /**
     * @param  list<Scope>  $scopes
     * @return array{key: ApiKey, secret: ApiKeySecret}
     */
    public static function apiKey(
        Project $project,
        array $scopes = [Scope::UsageWrite],
        string $name = 'Ingestion',
        ?DateTimeImmutable $issuedAt = null,
    ): array {
        $secret = ApiKeySecret::generate($project->environment);

        $key = ApiKey::issue(
            app(IdentifierGenerator::class)->generate(),
            $project->tenant(),
            $name,
            $secret,
            $scopes,
            $issuedAt ?? app(ClockInterface::class)->now(),
        );

        app(ApiKeyRepository::class)->save($key);

        return ['key' => $key, 'secret' => $secret];
    }
}
