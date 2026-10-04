<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Contract;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Quantity\Quantity;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * Billing's side of the period close: subscriptions, ended periods and
 * pricing. Invoicing passes quantities and gets charges back.
 */
interface SubscriptionBilling
{
    /**
     * All tenants; running or ended after $endedAfter.
     *
     * @return list<BillableSubscription>
     */
    public function billable(DateTimeImmutable $endedAfter): array;

    public function find(TenantContext $tenant, Uuid $subscriptionId): ?BillableSubscription;

    /**
     * Empty for an unknown subscription.
     *
     * @return list<BillablePeriod>
     */
    public function periodsEndedBy(TenantContext $tenant, Uuid $subscriptionId, DateTimeImmutable $from, DateTimeImmutable $endedBy): array;

    /**
     * One charge per price of the version in effect at $periodStart. A meter
     * absent from $usage counts as zero.
     *
     * @param array<string, Quantity> $usage keyed by meter id
     *
     * @return list<Charge>
     */
    public function charges(TenantContext $tenant, Uuid $subscriptionId, DateTimeImmutable $periodStart, array $usage): array;

    public function lapse(TenantContext $tenant, Uuid $subscriptionId, DateTimeImmutable $now): void;
}
