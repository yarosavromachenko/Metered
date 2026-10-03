<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Period;

/**
 * Both are whole months, so one clamping rule covers both.
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
