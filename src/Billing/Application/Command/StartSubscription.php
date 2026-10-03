<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Command;

use DateTimeImmutable;
use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * `startsAt` may be in the past: ended periods are invoiced by the next period
 * close. Null means now.
 */
final readonly class StartSubscription
{
    public function __construct(
        public TenantContext $tenant,
        public Uuid $customerId,
        public Uuid $versionId,
        public Actor $actor,
        public ?DateTimeImmutable $startsAt = null,
    ) {}
}
