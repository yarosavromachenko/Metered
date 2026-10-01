<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Command;

use Metered\Billing\Domain\Plan\PlanVersion;
use Metered\Billing\Domain\Plan\PlanVersionRepository;
use Metered\Shared\Application\Audit\AuditLogger;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Audit\AuditEntry;
use Metered\Tenancy\Application\Contract\Authorizer;
use Psr\Clock\ClockInterface;

final readonly class PublishPlanVersionHandler
{
    public function __construct(
        private PlanVersionRepository $versions,
        private Authorizer $authorizer,
        private ClockInterface $clock,
        private AuditLogger $audit,
    ) {}

    public function handle(PublishPlanVersion $command): PlanVersion
    {
        $this->authorizer->ensure($command->actor, $command->tenant->organizationId, Permission::ManageCatalog);

        $version = $this->versions->find($command->tenant, $command->versionId);

        if (! $version instanceof PlanVersion) {
            throw CatalogNotFound::of('plan version', $command->versionId);
        }

        $published = $version->publish($this->clock->now());
        $this->versions->save($published);

        $this->audit->record(new AuditEntry(
            organizationId: $command->tenant->organizationId,
            actor: $command->actor->label,
            action: 'plan_version.published',
            subjectType: 'plan_version',
            subjectId: $version->id->value,
            payload: ['plan_id' => $version->planId->value, 'number' => $version->number, 'prices' => count($version->prices)],
            occurredAt: $published->publishedAt ?? $this->clock->now(),
        ));

        return $published;
    }
}
