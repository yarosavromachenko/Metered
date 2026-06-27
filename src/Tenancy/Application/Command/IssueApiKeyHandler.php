<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Shared\Application\Audit\AuditLogger;
use Metered\Shared\Domain\Audit\AuditEntry;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Tenancy\Application\Authorization\PermissionGuard;
use Metered\Tenancy\Domain\ApiKey;
use Metered\Tenancy\Domain\ApiKeyRepository;
use Metered\Tenancy\Domain\ApiKeySecret;
use Metered\Tenancy\Domain\Permission;
use Metered\Tenancy\Domain\Project;
use Metered\Tenancy\Domain\ProjectRepository;
use Metered\Tenancy\Domain\Scope;
use Psr\Clock\ClockInterface;

/**
 * Issues a key for a project and hands the secret back exactly once.
 *
 * A key is a credential for everything the project holds, so issuing one is
 * owner authority — the same authority as adding a member. The check happens
 * here rather than in the screen that offers the button, because a button is
 * not a control.
 *
 * The audit entry records the prefix, never the secret: an audit trail that
 * leaks the credential it was written to protect has made the incident worse
 * than no trail at all.
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

        // The environment comes from the project, not from the caller. A key
        // whose environment disagrees with its project is refused by a foreign
        // key anyway; taking it from the project means nobody has to discover
        // that.
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
