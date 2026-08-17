<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Contract;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * What invoicing needs to know about a subscription to bill it: whose it is —
 * with the customer's reference and name, which an invoice prints — in which
 * currency, and since when.
 */
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
