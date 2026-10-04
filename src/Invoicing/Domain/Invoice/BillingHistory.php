<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Invoice;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Quantity\Quantity;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * What was already billed, for computing late lines.
 */
interface BillingHistory
{
    /**
     * Invoiced periods ended after $after, oldest first.
     *
     * @return list<InvoicePeriod>
     */
    public function periodsEndedAfter(TenantContext $tenant, Uuid $subscriptionId, DateTimeImmutable $after): array;

    /**
     * Including late lines.
     *
     * @return array<string, Quantity> keyed by meter id
     */
    public function billedQuantities(TenantContext $tenant, Uuid $subscriptionId, InvoicePeriod $covers): array;
}
