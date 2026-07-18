<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Pricing;

use Brick\Math\BigDecimal;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Quantity\Quantity;

/**
 * Each tier prices only the units that fall inside it.
 *
 * 1,500 units against "first 1,000 at 0.10, then 0.08" is 1,000 × 0.10 plus
 * 500 × 0.08. The tiers are summed exactly and the total rounded once, so the
 * line equals what an accountant gets by redoing the arithmetic on paper.
 */
final readonly class Graduated implements PricingModel
{
    private function __construct(public Tiers $tiers) {}

    public static function over(Tiers $tiers): self
    {
        return new self($tiers);
    }

    public function charge(Quantity $quantity): Money
    {
        $used = $quantity->toBigDecimal();
        $floor = BigDecimal::zero();
        $total = BigDecimal::zero();

        foreach ($this->tiers->all() as $tier) {
            $ceiling = $tier->limit?->toBigDecimal() ?? $used;
            // Nothing once the usage has run out below this tier.
            $inTier = BigDecimal::max(BigDecimal::min($used, $ceiling)->minus($floor), BigDecimal::zero());

            $total = $total->plus($tier->unitPrice->toBigDecimal()->multipliedBy($inTier));
            $floor = $ceiling;
        }

        return Money::rounded($total, $this->currency());
    }

    public function currency(): string
    {
        return $this->tiers->currency();
    }

    public function isUsageBased(): bool
    {
        return true;
    }
}
