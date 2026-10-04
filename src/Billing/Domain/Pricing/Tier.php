<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Pricing;

use Metered\Shared\Domain\Money\UnitPrice;
use Metered\Shared\Domain\Quantity\Quantity;

/**
 * Units up to and including $limit (as other billing providers read it); the
 * last tier has no limit.
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

    public function reaches(Quantity $quantity): bool
    {
        return !$this->limit instanceof Quantity || $quantity->compareTo($this->limit) <= 0;
    }
}
