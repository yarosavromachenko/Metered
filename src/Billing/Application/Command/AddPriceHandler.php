<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Command;

use Metered\Billing\Domain\Meter;
use Metered\Billing\Domain\MeterRepository;
use Metered\Billing\Domain\Plan\PlanVersion;
use Metered\Billing\Domain\Plan\PlanVersionRepository;
use Metered\Billing\Domain\Plan\Price;
use Metered\Shared\Application\Audit\AuditLogger;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Audit\AuditEntry;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Application\Contract\Authorizer;
use Psr\Clock\ClockInterface;

final readonly class AddPriceHandler
{
    public function __construct(
        private PlanVersionRepository $versions,
        private MeterRepository $meters,
        private Authorizer $authorizer,
        private IdentifierGenerator $ids,
        private ClockInterface $clock,
        private AuditLogger $audit,
    ) {}

    public function handle(AddPrice $command): Price
    {
        $this->authorizer->ensure($command->actor, $command->tenant->organizationId, Permission::ManageCatalog);

        $version = $this->versions->find($command->tenant, $command->versionId);

        if (! $version instanceof PlanVersion) {
            throw CatalogNotFound::of('plan version', $command->versionId);
        }

        $id = $this->ids->generate();

        if (!$command->meterId instanceof Uuid) {
            $price = Price::fixed($id, $command->model);
        } else {
            if (! $this->meters->find($command->tenant, $command->meterId) instanceof Meter) {
                throw CatalogNotFound::of('meter', $command->meterId);
            }

            $price = Price::metered($id, $command->model, $command->meterId);
        }

        $this->versions->save($version->withPrice($price));

        $this->audit->record(new AuditEntry(
            actor: $command->actor->label,
            action: 'price.added',
            subjectType: 'plan_version',
            subjectId: $version->id->value,
            payload: ['price_id' => $price->id->value, 'meter_id' => $price->meterId?->value],
            occurredAt: $this->clock->now(),
        ));

        return $price;
    }
}
