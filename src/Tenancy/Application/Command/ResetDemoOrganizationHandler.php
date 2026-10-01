<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Shared\Application\Audit\AuditLogger;
use Metered\Shared\Application\Transaction\Transactions;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Audit\AuditEntry;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Application\Contract\Authorizer;
use Metered\Tenancy\Application\Contract\TenantDataPurger;
use Metered\Tenancy\Domain\Organization;
use Metered\Tenancy\Domain\OrganizationRepository;
use Metered\Tenancy\Domain\Project;
use Metered\Tenancy\Domain\ProjectRepository;
use Metered\Tenancy\Domain\Scope;
use Psr\Clock\ClockInterface;

/**
 * "Reset demo data" in the panel: the purge's first half, without the second.
 *
 * Every module removes its rows exactly as it does for a purge — the demo
 * flag and the declared purge are what let invoices and the ledger go
 * (ADR-0008) — but the organization, its members, projects and keys stay,
 * so the owner is not signed out of a tenant that no longer exists. A key is
 * issued for filling it again, and returned to whoever asks for the data.
 */
final readonly class ResetDemoOrganizationHandler
{
    /**
     * @param  list<TenantDataPurger>  $purgers  in the order they run
     */
    public function __construct(
        private OrganizationRepository $organizations,
        private ProjectRepository $projects,
        private Authorizer $authorizer,
        private IssueApiKeyHandler $issueApiKey,
        private array $purgers,
        private Transactions $transactions,
        private AuditLogger $audit,
        private ClockInterface $clock,
    ) {}

    public function handle(ResetDemoOrganization $command): IssuedApiKey
    {
        $organizationId = $command->tenant->organizationId;
        $this->authorizer->ensure($command->actor, $organizationId, Permission::ManageTenant);

        return $this->transactions->run(function () use ($command, $organizationId): IssuedApiKey {
            $organization = $this->organizations->find($organizationId);

            if (! $organization instanceof Organization) {
                throw TenantNotFound::organization($organizationId);
            }

            if (! $organization->demo) {
                throw NotADemoOrganization::named($organization);
            }

            $projectIds = array_map(static fn(Project $project): Uuid => $project->id, $this->projects->listForOrganization($organizationId));

            foreach ($this->purgers as $purger) {
                $purger->purgeOrganization($organizationId, $projectIds);
            }

            $issued = $this->issueApiKey->handle(new IssueApiKey(
                $command->tenant,
                'Demo data',
                [Scope::UsageWrite, Scope::Admin],
                $command->actor,
            ));

            $this->audit->record(new AuditEntry(
                organizationId: $organizationId,
                actor: $command->actor->label,
                action: 'organization.demo_reset',
                subjectType: 'organization',
                subjectId: $organizationId->value,
                payload: ['projects' => count($projectIds)],
                occurredAt: $this->clock->now(),
            ));

            return $issued;
        });
    }
}
