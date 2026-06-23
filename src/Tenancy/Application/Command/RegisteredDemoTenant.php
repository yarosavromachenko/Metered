<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Shared\Domain\Identifier\Uuid;

final readonly class RegisteredDemoTenant
{
    public function __construct(
        public Uuid $userId,
        public ProvisionedTenant $tenant,
    ) {}
}
