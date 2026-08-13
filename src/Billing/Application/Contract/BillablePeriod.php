<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Contract;

use DateTimeImmutable;

/**
 * One period of a subscription that has ended and can be invoiced,
 * `[start, end)`, in UTC.
 */
final readonly class BillablePeriod
{
    public function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
    ) {}
}
