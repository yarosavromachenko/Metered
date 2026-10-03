<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Contract;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

final readonly class BillableSubscription
{
    public function __construct(
        public Uuid $id,
        public TenantContext $tenant,
        public Uuid $customerId,
        public string $customerReference,
        public string $customerName,
        public string $currency,
        public DateTimeImmutable $anchorAt,
    ) {}
}
