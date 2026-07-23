<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Command;

use Metered\Billing\Domain\Pricing\PricingModel;
use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * Adds a price to a draft version. The model arrives already built — the API
 * and the panel each parse their own input into one — and the meter is named
 * when, and only when, the model depends on usage.
 */
final readonly class AddPrice
{
    public function __construct(
        public TenantContext $tenant,
        public Uuid $versionId,
        public PricingModel $model,
        public ?Uuid $meterId,
        public Actor $actor,
    ) {}
}
