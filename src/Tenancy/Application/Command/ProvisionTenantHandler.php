<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Shared\Application\Audit\AuditLogger;
use Metered\Shared\Application\Transaction\Transactions;
use Metered\Shared\Domain\Audit\AuditEntry;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Identifier\Uuid;
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

/**
 * Brings a whole tenant into existence: organization, first project, first
 * key, and the person who owns it when there is one.
 *
 * All of it inside one transaction. An organization without a project, or a
 * project without a key, is a tenant nobody can use and nobody can finish —
 * the kind of state that is repaired by hand at an inconvenient hour.
 *
 * Nothing is checked against a membership here, because this is the operation
 * that creates the organization a membership could refer to. The authority to
 * run it comes from outside: an operator at a console, or a sign-up form that
 * demo mode has opened. The owner membership is written before the key is
 * issued, so the key is issued by somebody who is already allowed to have one.
 *
 * It delegates the key to IssueApiKeyHandler rather than repeating it. There
 * is one way to issue a key in this system, and the sign-up path must not
 * become a second one that forgets the audit entry.
 */
final readonly class ProvisionTenantHandler
{
    public function __construct(
        private OrganizationRepository $organizations,
        private ProjectRepository $projects,
        private MembershipRepository $memberships,
        private IssueApiKeyHandler $issueApiKey,
        private Transactions $transactions,
        private IdentifierGenerator $ids,
        private ClockInterface $clock,
        private AuditLogger $audit,
    ) {}

    public function handle(ProvisionTenant $command): ProvisionedTenant
    {
        return $this->transactions->run(function () use ($command): ProvisionedTenant {
            $now = $this->clock->now();

            $organization = Organization::register(
                $this->ids->generate(),
                $command->organizationName,
                $this->availableSlug($command->organizationName),
                $now,
                $command->demo,
            );

            $this->organizations->save($organization);

            if ($command->ownerUserId instanceof Uuid) {
                $this->memberships->save(new Membership(
                    $this->ids->generate(),
                    $organization->id,
                    $command->ownerUserId,
                    Role::Owner,
                    $now,
                ));
            }

            $project = Project::open(
                $this->ids->generate(),
                $organization->id,
                $command->projectName,
                Slug::fromName($command->projectName),
                $command->environment,
                $command->currency,
                $now,
            );

            $this->projects->save($project);

            $issued = $this->issueApiKey->handle(new IssueApiKey(
                $project->tenant(),
                'Default key',
                // The first key can do everything the API offers: it is the
                // one the tenant has until they issue narrower ones.
                [Scope::UsageWrite, Scope::Admin],
                $command->actor,
            ));

            $this->audit->record(new AuditEntry(
                actor: $command->actor->label,
                action: 'organization.provisioned',
                subjectType: 'organization',
                subjectId: $organization->id->value,
                payload: [
                    'slug' => $organization->slug->value,
                    'project_id' => $project->id->value,
                    'project_slug' => $project->slug->value,
                    'environment' => $project->environment->value,
                    'currency' => $project->currency,
                    'owner_user_id' => $command->ownerUserId?->value,
                    'demo' => $command->demo,
                ],
                occurredAt: $now,
            ));

            return new ProvisionedTenant($organization, $project, $issued->key, $issued->secret);
        });
    }

    /**
     * Organization slugs are unique platform-wide, and on the demo instance
     * strangers pick the names. A taken slug gets a short random suffix
     * instead of an error: "acme" and "acme-3f9b" are both addressable, and
     * the second visitor to type Acme is not asked to rename their company.
     */
    private function availableSlug(string $name): Slug
    {
        $slug = Slug::fromName($name);

        if (!$this->organizations->findBySlug($slug) instanceof Organization) {
            return $slug;
        }

        return Slug::fromString(substr($slug->value, 0, 58) . '-' . bin2hex(random_bytes(2)));
    }
}
