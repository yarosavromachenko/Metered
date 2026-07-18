<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Pricing;

use Metered\Billing\Domain\Exception\InvalidPricing;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Quantity\Quantity;

/**
 * A fixed amount per period, whatever was used.
 *
 * A first period shorter than a full one is still charged in full: proration
 * is out of scope in v1 (docs/domain.md, pricing models).
 */
final readonly class FlatFee implements PricingModel
{
    private function __construct(private Money $amount) {}

    public static function of(Money $amount): self
    {
        if ($amount->isNegative()) {
            throw InvalidPricing::negativeFlatFee((string) $amount);
        }

        return new self($amount);
    }

    public function charge(Quantity $quantity): Money
    {
        return $this->amount;
    }

    public function currency(): string
    {
        return $this->amount->currency();
    }

    public function isUsageBased(): bool
    {
        return false;
    }

    public function amount(): Money
    {
        return $this->amount;
    }
}
