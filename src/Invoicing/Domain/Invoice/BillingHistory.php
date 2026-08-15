<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Invoice;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Quantity\Quantity;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * What has already been billed, read from the invoices themselves — the
 * other half of every late line.
 */
interface BillingHistory
{
    /**
     * The periods the subscription has invoices for that ended after $after,
     * oldest first: the ones late usage can still reach.
     *
     * @return list<InvoicePeriod>
     */
    public function periodsEndedAfter(TenantContext $tenant, Uuid $subscriptionId, DateTimeImmutable $after): array;

    /**
     * The quantity billed so far for each meter over $covers, on the invoice
     * for that period and on every late line since.
     *
     * @return array<string, Quantity> keyed by meter id
     */
    public function billedQuantities(TenantContext $tenant, Uuid $subscriptionId, InvoicePeriod $covers): array;
}
