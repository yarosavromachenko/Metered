<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Command;

use Metered\Billing\Domain\Plan\Plan;
use Metered\Billing\Domain\Plan\PlanCode;
use Metered\Billing\Domain\Plan\PlanRepository;
use Metered\Shared\Application\Audit\AuditLogger;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Audit\AuditEntry;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Tenancy\Application\Contract\Authorizer;
use Psr\Clock\ClockInterface;

final readonly class CreatePlanHandler
{
    public function __construct(
        private PlanRepository $plans,
        private Authorizer $authorizer,
        private IdentifierGenerator $ids,
        private ClockInterface $clock,
        private AuditLogger $audit,
    ) {}

    public function handle(CreatePlan $command): Plan
    {
        $this->authorizer->ensure($command->actor, $command->tenant->organizationId, Permission::ManageCatalog);

        $code = PlanCode::fromString($command->code);

        if ($this->plans->findByCode($command->tenant, $code) instanceof Plan) {
            throw PlanCodeTaken::withCode($code);
        }

        $plan = Plan::create($this->ids->generate(), $command->tenant, $code, $command->name, $this->clock->now());

        $this->plans->save($plan);

        $this->audit->record(new AuditEntry(
            actor: $command->actor->label,
            action: 'plan.created',
            subjectType: 'plan',
            subjectId: $plan->id->value,
            payload: ['project_id' => $plan->tenant->projectId->value, 'code' => $plan->code->value],
            occurredAt: $plan->createdAt,
        ));

        return $plan;
    }
}
