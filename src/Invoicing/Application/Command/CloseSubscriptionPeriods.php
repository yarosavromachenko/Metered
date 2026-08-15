<?php

declare(strict_types=1);

namespace Metered\Invoicing\Application\Command;

use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * Invoices every period of one subscription whose grace window has passed.
 */
final readonly class CloseSubscriptionPeriods
{
    public function __construct(
        public TenantContext $tenant,
        public Uuid $subscriptionId,
        public Actor $actor,
    ) {}
}
