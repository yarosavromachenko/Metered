<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Command;

use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Tenant\TenantContext;

final readonly class RegisterCustomer
{
    public function __construct(
        public TenantContext $tenant,
        public string $reference,
        public string $name,
        public Actor $actor,
    ) {}
}
