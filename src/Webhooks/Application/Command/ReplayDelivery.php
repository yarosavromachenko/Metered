<?php

declare(strict_types=1);

namespace Metered\Webhooks\Application\Command;

use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

final readonly class ReplayDelivery
{
    public function __construct(
        public TenantContext $tenant,
        public Uuid $deliveryId,
        public Actor $actor,
    ) {}
}
