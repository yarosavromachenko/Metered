<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Pricing;

use Metered\Billing\Domain\Exception\InvalidPricing;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Quantity\Quantity;

/**
 * Fixed amount per period, charged in full even for a short first period (no
 * proration, docs/domain.md).
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

    public function calculation(Quantity $quantity): array
    {
        return [sprintf('%s per period, whatever was used', $this->amount)];
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
