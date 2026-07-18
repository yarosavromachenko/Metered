<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Period;

/**
 * How far apart a cycle's boundaries are. Both are whole months, which is what
 * lets one clamping rule serve both.
 */
enum BillingInterval: string
{
    case Month = 'month';
    case Year = 'year';

    public function months(): int
    {
        return match ($this) {
            self::Month => 1,
            self::Year => 12,
        };
    }
}
