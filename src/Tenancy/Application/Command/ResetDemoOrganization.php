<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * Wipe a demo organization's data and keep the organization: its people,
 * projects and keys stay, everything they made goes. `tenant` is the project
 * the fresh key is issued in — the one the owner is looking at.
 */
final readonly class ResetDemoOrganization
{
    public function __construct(
        public TenantContext $tenant,
        public Actor $actor,
    ) {}
}
