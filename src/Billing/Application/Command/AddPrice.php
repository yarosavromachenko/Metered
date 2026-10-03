<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Command;

use Metered\Billing\Domain\Pricing\PricingModel;
use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * The model is built by the caller; the meter is set only for usage-based
 * models.
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
