<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Command;

use Metered\Billing\Domain\Meter;
use Metered\Billing\Domain\MeterCode;
use Metered\Billing\Domain\MeterRepository;
use Metered\Shared\Application\Audit\AuditLogger;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Audit\AuditEntry;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Tenancy\Application\Contract\Authorizer;
use Psr\Clock\ClockInterface;

/**
 * Defines what a project measures.
 *
 * Shaping the catalog is `catalog.manage` — the authority an admin has and a
 * billing operator does not, because deciding what is measured and deciding
 * what somebody owes are different jobs (ADR-0017).
 *
 * The duplicate check here is a courtesy, not the guarantee: two panels
 * submitting the same code at the same moment both pass it, and the unique
 * index on (project_id, code) is what refuses the second. What this adds is a
 * sentence a person can read instead of a constraint violation.
 */
final readonly class DefineMeterHandler
{
    public function __construct(
        private MeterRepository $meters,
        private Authorizer $authorizer,
        private IdentifierGenerator $ids,
        private ClockInterface $clock,
        private AuditLogger $audit,
    ) {}

    public function handle(DefineMeter $command): Meter
    {
        $this->authorizer->ensure($command->actor, $command->tenant->organizationId, Permission::ManageCatalog);

        $code = MeterCode::fromString($command->code);

        if ($this->meters->findByCode($command->tenant, $code) instanceof Meter) {
            throw MeterCodeTaken::withCode($code);
        }

        $meter = Meter::define(
            $this->ids->generate(),
            $command->tenant,
            $code,
            $command->name,
            $command->aggregation,
            $this->clock->now(),
        );

        $this->meters->save($meter);

        $this->audit->record(new AuditEntry(
            actor: $command->actor->label,
            action: 'meter.defined',
            subjectType: 'meter',
            subjectId: $meter->id->value,
            payload: [
                'project_id' => $meter->tenant->projectId->value,
                'code' => $meter->code->value,
                'aggregation' => $meter->aggregation->value,
            ],
            occurredAt: $meter->definedAt,
        ));

        return $meter;
    }
}
