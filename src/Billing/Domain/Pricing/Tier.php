<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Pricing;

use Metered\Shared\Domain\Money\UnitPrice;
use Metered\Shared\Domain\Quantity\Quantity;

/**
 * One band of a tiered price: the units up to and including $limit, priced at
 * $unitPrice. The last band of a table has no limit.
 *
 * The limit is inclusive — a quantity of exactly 1,000 lies in the tier that
 * ends at 1,000. That is the reading every major billing provider uses, and
 * the one a customer reading "first 1,000 units" expects.
 */
final readonly class Tier
{
    private function __construct(
        public ?Quantity $limit,
        public UnitPrice $unitPrice,
    ) {}

    public static function upTo(Quantity $limit, UnitPrice $unitPrice): self
    {
        return new self($limit, $unitPrice);
    }

    public static function unbounded(UnitPrice $unitPrice): self
    {
        return new self(null, $unitPrice);
    }

    /**
     * Whether $quantity falls within this tier's reach, from zero.
     */
    public function reaches(Quantity $quantity): bool
    {
        return !$this->limit instanceof Quantity || $quantity->compareTo($this->limit) <= 0;
    }
}
