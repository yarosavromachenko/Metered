<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Shared\Application\Audit\AuditLogger;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Audit\AuditEntry;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Tenancy\Application\Authorization\PermissionGuard;
use Metered\Tenancy\Domain\Project;
use Metered\Tenancy\Domain\ProjectRepository;
use Metered\Tenancy\Domain\Slug;
use Psr\Clock\ClockInterface;

/**
 * Opens another project inside an existing organization — the second
 * environment, the sandbox, the one for next year's product.
 *
 * The currency is fixed here and never again: everything priced beneath a
 * project uses it, and the system converts nothing (ADR-0007). A project in
 * the wrong currency is replaced, not corrected.
 */
final readonly class CreateProjectHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private PermissionGuard $guard,
        private IdentifierGenerator $ids,
        private ClockInterface $clock,
        private AuditLogger $audit,
    ) {}

    public function handle(CreateProject $command): Project
    {
        $this->guard->ensure($command->actor, $command->organizationId, Permission::ManageTenant);

        $slug = Slug::fromName($command->name);

        if ($this->projects->findBySlug($command->organizationId, $slug) instanceof Project) {
            throw ProjectSlugTaken::withSlug($slug);
        }

        $project = Project::open(
            $this->ids->generate(),
            $command->organizationId,
            $command->name,
            $slug,
            $command->environment,
            $command->currency,
            $this->clock->now(),
        );

        $this->projects->save($project);

        $this->audit->record(new AuditEntry(
            actor: $command->actor->label,
            action: 'project.created',
            subjectType: 'project',
            subjectId: $project->id->value,
            payload: [
                'organization_id' => $project->organizationId->value,
                'slug' => $project->slug->value,
                'environment' => $project->environment->value,
                'currency' => $project->currency,
            ],
            occurredAt: $project->createdAt,
        ));

        return $project;
    }
}
