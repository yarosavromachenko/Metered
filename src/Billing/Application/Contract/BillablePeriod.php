<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Contract;

use DateTimeImmutable;

/**
 * An ended period, `[start, end)` in UTC.
 */
final readonly class BillablePeriod
{
    public function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
    ) {}
}
