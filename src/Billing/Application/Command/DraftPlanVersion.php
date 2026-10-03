<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Command;

use Metered\Billing\Domain\Period\BillingInterval;
use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * Empty draft in the project's currency.
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
