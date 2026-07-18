<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Pricing;

use LogicException;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Quantity\Quantity;

/**
 * The tier the total reaches prices every unit.
 *
 * 1,500 units against "first 1,000 at 0.10, then 0.08" is 1,500 × 0.08. Unlike
 * graduated pricing, one more unit across a boundary can lower the bill — that
 * is the point of a volume discount, not an anomaly.
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

        // Unreachable: Tiers guarantees an unbounded last tier, which reaches
        // every quantity. Stated rather than returned, so a broken guarantee
        // fails loudly instead of billing zero.
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
