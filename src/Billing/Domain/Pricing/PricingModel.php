<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Pricing;

use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Quantity\Quantity;

/**
 * How one price turns a period's usage into money.
 *
 * Four implementations, one per model the catalog offers, and a new model is a
 * new class rather than another branch in an existing one. Every
 * implementation is a pure function of the quantity: no clock, no database,
 * nothing that could make the same usage cost differently on a second run.
 */
interface PricingModel
{
    /**
     * The charge for a period in which the price's meter reported $quantity.
     * Rounded to the currency's minor unit exactly once.
     */
    public function charge(Quantity $quantity): Money;

    public function currency(): string;

    /**
     * Whether the charge depends on usage at all — and therefore whether the
     * price needs a meter to read it from.
     */
    public function isUsageBased(): bool;
}
