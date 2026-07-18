<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Pricing;

use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Money\UnitPrice;
use Metered\Shared\Domain\Quantity\Quantity;

/**
 * Quantity times one unit price.
 */
final readonly class PerUnit implements PricingModel
{
    private function __construct(public UnitPrice $unitPrice) {}

    public static function at(UnitPrice $unitPrice): self
    {
        return new self($unitPrice);
    }

    public function charge(Quantity $quantity): Money
    {
        return $this->unitPrice->multipliedBy($quantity);
    }

    public function currency(): string
    {
        return $this->unitPrice->currency();
    }

    public function isUsageBased(): bool
    {
        return true;
    }
}
