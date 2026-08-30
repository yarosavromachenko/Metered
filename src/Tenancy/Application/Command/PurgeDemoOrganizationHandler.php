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
 * Deletes a demo tenant: every module's rows, then the organization with its
 * projects, keys and memberships, then the accounts that belonged to nothing
 * else — all in one transaction, so a purge that fails halfway leaves the
 * tenant whole rather than half gone.
 *
 * Only demo organizations, checked here for a readable refusal and again by
 * the database, which is what actually guarantees it (ADR-0008, ADR-0016).
 *
 * The audit log keeps its entries about the tenant. It is a hash chain, and
 * removing a link would break every link after it; what it records about a
 * demo — slugs, key prefixes, e-mail addresses typed into a sign-up form — is
 * what a purge is audited against.
 *
 * A key cached by the authenticator may still be accepted for up to its cache
 * lifetime, the same bound revocation has; anything it then tries to write
 * refers to a project that no longer exists and is refused.
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
