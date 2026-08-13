<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Contract;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Quantity\Quantity;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * How much of each meter a customer used over a stretch of time, as the
 * aggregates hold it — what an invoice is built from.
 *
 * Aggregates are hourly. A bucket counts toward the stretch its start falls
 * in, so consecutive stretches share no bucket and miss none, whatever
 * instants they begin at (assumptions, 24).
 */
interface UsageTotals
{
    /**
     * Totals folded the way each meter aggregates: added for `sum` and
     * `count`, the peak for `max`. A meter with no usage is absent.
     *
     * @return array<string, Quantity> keyed by meter id
     */
    public function forPeriod(TenantContext $tenant, Uuid $customerId, DateTimeImmutable $from, DateTimeImmutable $to): array;
}
