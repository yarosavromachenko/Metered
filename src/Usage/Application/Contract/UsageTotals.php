<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Contract;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Quantity\Quantity;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * Usage per meter over a period, from hourly aggregates. A bucket belongs to
 * the period its start falls in (assumption 24).
 */
interface UsageTotals
{
    /**
     * Summed for `sum` and `count`, peak for `max`. Meters without usage are absent.
     *
     * @return array<string, Quantity> keyed by meter id
     */
    public function forPeriod(TenantContext $tenant, Uuid $customerId, DateTimeImmutable $from, DateTimeImmutable $to): array;
}
