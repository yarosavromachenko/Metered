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
        return $this->total($this->slices($quantity));
    }

    public function calculation(Quantity $quantity): array
    {
        $slices = $this->slices($quantity);
        $labels = $this->tiers->labels();
        $steps = [];

        foreach ($slices as $slice) {
            $steps[] = sprintf(
                '%s: %s × %s = %s',
                $labels[$slice['index']],
                Tiers::plain($slice['units']),
                Tiers::price($slice['tier']->unitPrice),
                Tiers::plain($slice['amount']),
            );
        }

        $steps[] = sprintf('total %s, rounded once', $this->total($slices));

        return $steps;
    }

    public function currency(): string
    {
        return $this->tiers->currency();
    }

    public function isUsageBased(): bool
    {
        return true;
    }

    /**
     * The part of the usage each tier prices, walked once for both the charge
     * and the calculation shown beside it, so the two cannot disagree. A tier
     * the usage never reaches has no slice.
     *
     * @return list<array{index: int, tier: Tier, units: BigDecimal, amount: BigDecimal}>
     */
    private function slices(Quantity $quantity): array
    {
        $used = $quantity->toBigDecimal();
        $floor = BigDecimal::zero();
        $slices = [];

        foreach ($this->tiers->all() as $index => $tier) {
            $ceiling = $tier->limit?->toBigDecimal() ?? $used;
            $units = BigDecimal::min($used, $ceiling)->minus($floor);

            if ($units->isPositive()) {
                $slices[] = [
                    'index' => $index,
                    'tier' => $tier,
                    'units' => $units,
                    'amount' => $tier->unitPrice->toBigDecimal()->multipliedBy($units),
                ];
            }

            $floor = $ceiling;
        }

        return $slices;
    }

    /**
     * @param list<array{index: int, tier: Tier, units: BigDecimal, amount: BigDecimal}> $slices
     */
    private function total(array $slices): Money
    {
        $total = BigDecimal::zero();

        foreach ($slices as $slice) {
            $total = $total->plus($slice['amount']);
        }

        return Money::rounded($total, $this->currency());
    }
}
