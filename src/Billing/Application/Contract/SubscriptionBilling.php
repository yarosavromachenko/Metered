<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Contract;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Quantity\Quantity;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * The catalog's side of closing a period: which subscriptions there are, which
 * of their periods have ended, and what a period costs.
 *
 * Invoicing owns what has been invoiced; Billing owns subscriptions, cycles
 * and prices. Pricing stays here, as the single place money is computed from
 * usage (docs/domain.md, pricing models): invoicing hands in quantities and
 * gets back charges.
 */
interface SubscriptionBilling
{
    /**
     * Every subscription, in every tenant, still running or ended after
     * $endedAfter — the ones that may have a period left to invoice.
     *
     * @return list<BillableSubscription>
     */
    public function billable(DateTimeImmutable $endedAfter): array;

    public function find(TenantContext $tenant, Uuid $subscriptionId): ?BillableSubscription;

    /**
     * The subscription's periods that start at or after $from and ended by
     * $endedBy, in order. Empty for a subscription that does not exist.
     *
     * @return list<BillablePeriod>
     */
    public function periodsEndedBy(TenantContext $tenant, Uuid $subscriptionId, DateTimeImmutable $from, DateTimeImmutable $endedBy): array;

    /**
     * What the plan version billing the period that starts at $periodStart
     * charges, one charge per price. A meter missing from $usage was not used.
     *
     * @param array<string, Quantity> $usage keyed by meter id
     *
     * @return list<Charge>
     */
    public function charges(TenantContext $tenant, Uuid $subscriptionId, DateTimeImmutable $periodStart, array $usage): array;

    /**
     * Turns a pending cancellation whose end has passed into a cancellation.
     * Anything else is left as it is.
     */
    public function lapse(TenantContext $tenant, Uuid $subscriptionId, DateTimeImmutable $now): void;
}
