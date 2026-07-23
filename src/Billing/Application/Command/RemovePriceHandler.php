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

final readonly class RemovePriceHandler
{
    public function __construct(
        private PlanVersionRepository $versions,
        private Authorizer $authorizer,
        private ClockInterface $clock,
        private AuditLogger $audit,
    ) {}

    public function handle(RemovePrice $command): PlanVersion
    {
        $this->authorizer->ensure($command->actor, $command->tenant->organizationId, Permission::ManageCatalog);

        $version = $this->versions->find($command->tenant, $command->versionId);

        if (! $version instanceof PlanVersion) {
            throw CatalogNotFound::of('plan version', $command->versionId);
        }

        $changed = $version->withoutPrice($command->priceId);
        $this->versions->save($changed);

        $this->audit->record(new AuditEntry(
            actor: $command->actor->label,
            action: 'price.removed',
            subjectType: 'plan_version',
            subjectId: $version->id->value,
            payload: ['price_id' => $command->priceId->value],
            occurredAt: $this->clock->now(),
        ));

        return $changed;
    }
}
