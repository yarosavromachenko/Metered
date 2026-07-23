<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Command;

use Metered\Billing\Domain\Period\BillingInterval;
use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * Opens the next version of a plan as an empty draft. Its currency is the
 * project's; only the interval is chosen.
 */
final readonly class DraftPlanVersion
{
    public function __construct(
        public TenantContext $tenant,
        public Uuid $planId,
        public BillingInterval $interval,
        public Actor $actor,
    ) {}
}
