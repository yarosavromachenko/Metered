<?php

declare(strict_types=1);

namespace Metered\Webhooks\Application\Delivery;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

final readonly class AttemptDelivery
{
    public function __construct(
        public TenantContext $tenant,
        public Uuid $deliveryId,
    ) {}
}
