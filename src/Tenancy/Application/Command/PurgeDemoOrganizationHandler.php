<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Shared\Application\Audit\AuditLogger;
use Metered\Shared\Application\Transaction\Transactions;
use Metered\Shared\Domain\Audit\AuditEntry;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Application\Contract\TenantDataPurger;
use Metered\Tenancy\Application\Identity\UserAccounts;
use Metered\Tenancy\Domain\Membership;
use Metered\Tenancy\Domain\MembershipRepository;
use Metered\Tenancy\Domain\Organization;
use Metered\Tenancy\Domain\OrganizationRepository;
use Metered\Tenancy\Domain\Project;
use Metered\Tenancy\Domain\ProjectRepository;
use Psr\Clock\ClockInterface;

/**
 * In one transaction: every module's rows, the organization (projects, keys,
 * memberships cascade), then accounts left without an organization. Demo
 * organizations only, enforced by the database (ADR-0008, ADR-0016).
 *
 * Audit entries stay: deleting them would break the hash chain. A cached key
 * may authenticate until its cache entry expires, but its writes fail on the
 * missing project.
 */
final readonly class PurgeDemoOrganizationHandler
{
    /**
     * @param  list<TenantDataPurger>  $purgers  in the order they run
     */
    public function __construct(
        private OrganizationRepository $organizations,
        private ProjectRepository $projects,
        private MembershipRepository $memberships,
        private UserAccounts $users,
        private array $purgers,
        private Transactions $transactions,
        private AuditLogger $audit,
        private ClockInterface $clock,
    ) {}

    public function handle(PurgeDemoOrganization $command): void
    {
        $this->transactions->run(function () use ($command): void {
            $organization = $this->organizations->find($command->organizationId);

            if (! $organization instanceof Organization) {
                throw TenantNotFound::organization($command->organizationId);
            }

            if (! $organization->demo) {
                throw NotADemoOrganization::named($organization);
            }

            $projectIds = array_map(static fn(Project $project): Uuid => $project->id, $this->projects->listForOrganization($organization->id));
            $memberIds = array_map(static fn(Membership $membership): Uuid => $membership->userId, $this->memberships->forOrganization($organization->id));

            foreach ($this->purgers as $purger) {
                $purger->purgeOrganization($organization->id, $projectIds);
            }

            $this->organizations->remove($organization->id);
            $this->users->removeUnaffiliated($memberIds);

            $this->audit->record(new AuditEntry(
                organizationId: $organization->id,
                actor: $command->actor->label,
                action: 'organization.purged',
                subjectType: 'organization',
                subjectId: $organization->id->value,
                payload: [
                    'slug' => $organization->slug->value,
                    'projects' => count($projectIds),
                    'members' => count($memberIds),
                ],
                occurredAt: $this->clock->now(),
            ));
        });
    }
}
