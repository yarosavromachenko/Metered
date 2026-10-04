<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Command;

use Metered\Billing\Domain\Plan\Plan;
use Metered\Billing\Domain\Plan\PlanRepository;
use Metered\Billing\Domain\Plan\PlanVersion;
use Metered\Billing\Domain\Plan\PlanVersionRepository;
use Metered\Shared\Application\Audit\AuditLogger;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Audit\AuditEntry;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Tenancy\Application\Contract\Authorizer;
use Metered\Tenancy\Application\Contract\ProjectDirectory;
use Psr\Clock\ClockInterface;

final readonly class DraftPlanVersionHandler
{
    public function __construct(
        private PlanRepository $plans,
        private PlanVersionRepository $versions,
        private ProjectDirectory $projects,
        private Authorizer $authorizer,
        private IdentifierGenerator $ids,
        private ClockInterface $clock,
        private AuditLogger $audit,
    ) {}

    public function handle(DraftPlanVersion $command): PlanVersion
    {
        $this->authorizer->ensure($command->actor, $command->tenant->organizationId, Permission::ManageCatalog);

        $plan = $this->plans->find($command->tenant, $command->planId);
        $currency = $this->projects->currencyOf($command->tenant);

        if (! $plan instanceof Plan || $currency === null) {
            throw CatalogNotFound::of('plan', $command->planId);
        }

        // Concurrent drafts: the unique (plan, number) constraint decides.
        $version = PlanVersion::draft(
            $this->ids->generate(),
            $command->tenant,
            $plan->id,
            $this->versions->nextNumber($command->tenant, $plan->id),
            $currency,
            $command->interval,
            $this->clock->now(),
        );

        $this->versions->save($version);

        $this->audit->record(new AuditEntry(
            organizationId: $command->tenant->organizationId,
            actor: $command->actor->label,
            action: 'plan_version.drafted',
            subjectType: 'plan_version',
            subjectId: $version->id->value,
            payload: ['plan_id' => $plan->id->value, 'number' => $version->number],
            occurredAt: $version->createdAt,
        ));

        return $version;
    }
}
