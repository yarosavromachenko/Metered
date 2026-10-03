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
 * Requires `catalog.manage` (ADR-0017). The duplicate check only gives a
 * readable message; the unique index on (project_id, code) enforces it.
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
            organizationId: $command->tenant->organizationId,
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
