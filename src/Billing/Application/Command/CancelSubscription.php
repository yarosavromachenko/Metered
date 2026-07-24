<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Command;

use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * Ends a subscription at the end of its current period, or — when
 * $immediately — at once.
 */
final readonly class CancelSubscription
{
    public function __construct(
        public TenantContext $tenant,
        public Uuid $subscriptionId,
        public bool $immediately,
        public Actor $actor,
    ) {}
}
