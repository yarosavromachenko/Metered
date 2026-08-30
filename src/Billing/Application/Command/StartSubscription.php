<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Command;

use DateTimeImmutable;
use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * `startsAt` backdates the subscription: it is anchored there, and the
 * periods that have already ended are invoiced by the next period close, as
 * they would have been had it started then. Null starts it now.
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
