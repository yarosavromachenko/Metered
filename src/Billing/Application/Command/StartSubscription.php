<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Command;

use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

final readonly class StartSubscription
{
    public function __construct(
        public TenantContext $tenant,
        public Uuid $customerId,
        public Uuid $versionId,
        public Actor $actor,
    ) {}
}
