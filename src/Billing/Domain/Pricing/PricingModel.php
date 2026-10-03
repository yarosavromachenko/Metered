<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Pricing;

use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Quantity\Quantity;

/**
 * One implementation per pricing model; each is a pure function of the
 * quantity.
 */
interface PricingModel
{
    /**
     * Rounded to the minor unit once.
     */
    public function charge(Quantity $quantity): Money;

    /**
     * How charge() was computed, one line per step, shown on the invoice.
     *
     * @return list<string>
     */
    public function calculation(Quantity $quantity): array;

    public function currency(): string;

    /**
     * Usage-based prices need a meter.
     */
    public function isUsageBased(): bool;
}
