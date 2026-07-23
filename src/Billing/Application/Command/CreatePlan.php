<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Command;

use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Tenant\TenantContext;

final readonly class CreatePlan
{
    public function __construct(
        public TenantContext $tenant,
        public string $code,
        public string $name,
        public Actor $actor,
    ) {}
}
