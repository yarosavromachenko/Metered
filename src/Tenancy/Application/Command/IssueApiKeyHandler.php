<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Shared\Application\Audit\AuditLogger;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Audit\AuditEntry;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Tenancy\Application\Authorization\PermissionGuard;
use Metered\Tenancy\Domain\ApiKey;
use Metered\Tenancy\Domain\ApiKeyRepository;
use Metered\Tenancy\Domain\ApiKeySecret;
use Metered\Tenancy\Domain\Project;
use Metered\Tenancy\Domain\ProjectRepository;
use Metered\Tenancy\Domain\Scope;
use Psr\Clock\ClockInterface;

/**
 * Requires ManageTenant. The secret is returned once; the audit entry records
 * only the prefix.
 */
final readonly class IssueApiKeyHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private ApiKeyRepository $keys,
        private PermissionGuard $guard,
        private IdentifierGenerator $ids,
        private ClockInterface $clock,
        private AuditLogger $audit,
    ) {}

    public function handle(IssueApiKey $command): IssuedApiKey
    {
        $project = $this->projects->find($command->tenant);

        if (! $project instanceof Project) {
            throw TenantNotFound::project($command->tenant);
        }

        $this->guard->ensure($command->actor, $project->organizationId, Permission::ManageTenant);

        // Environment comes from the project; a foreign key enforces the match.
        $secret = ApiKeySecret::generate($project->environment);

        $key = ApiKey::issue(
            $this->ids->generate(),
            $project->tenant(),
            $command->name,
            $secret,
            $command->scopes,
            $this->clock->now(),
        );

        $this->keys->save($key);

        $this->audit->record(new AuditEntry(
            organizationId: $project->organizationId,
            actor: $command->actor->label,
            action: 'api_key.issued',
            subjectType: 'api_key',
            subjectId: $key->id->value,
            payload: [
                'project_id' => $key->tenant->projectId->value,
                'prefix' => $key->prefix,
                'scopes' => array_map(static fn(Scope $scope): string => $scope->value, $key->scopes),
            ],
            occurredAt: $key->createdAt,
        ));

        return new IssuedApiKey($key, $secret);
    }
}
