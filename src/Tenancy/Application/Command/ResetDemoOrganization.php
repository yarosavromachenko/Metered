<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * `tenant` is the project the new key is issued in.
 */
final readonly class ResetDemoOrganization
{
    public function __construct(
        public TenantContext $tenant,
        public Actor $actor,
    ) {}
}
