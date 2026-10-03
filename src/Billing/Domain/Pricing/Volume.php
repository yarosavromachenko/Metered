<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Pricing;

use LogicException;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Quantity\Quantity;

/**
 * The tier the total reaches prices all units: 1,500 units against "first
 * 1,000 at 0.10, then 0.08" is 1,500 × 0.08. Crossing a boundary can lower
 * the bill.
 */
final readonly class Volume implements PricingModel
{
    private function __construct(public Tiers $tiers) {}

    public static function over(Tiers $tiers): self
    {
        return new self($tiers);
    }

    public function charge(Quantity $quantity): Money
    {
        foreach ($this->tiers->all() as $tier) {
            if ($tier->reaches($quantity)) {
                return $tier->unitPrice->multipliedBy($quantity);
            }
        }

        // Unreachable: the last tier is unbounded.
        throw new LogicException('A tier table without an unbounded last tier reached pricing.');
    }

    public function calculation(Quantity $quantity): array
    {
        $labels = $this->tiers->labels();

        foreach ($this->tiers->all() as $index => $tier) {
            if ($tier->reaches($quantity)) {
                return [sprintf(
                    '%s lies in the tier %s, which prices every unit: %s × %s = %s',
                    Tiers::plain($quantity->toBigDecimal()),
                    $labels[$index],
                    Tiers::plain($quantity->toBigDecimal()),
                    Tiers::price($tier->unitPrice),
                    $this->charge($quantity),
                )];
            }
        }

        throw new LogicException('A tier table without an unbounded last tier reached pricing.');
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
