<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Pricing;

use Metered\Billing\Domain\Exception\InvalidPricing;
use Metered\Shared\Domain\Quantity\Quantity;

/**
 * An ordered tier table that can price any quantity.
 *
 * Three rules make that true, and a table breaking any of them is refused when
 * it is built rather than discovered when an invoice is: limits rise strictly
 * from zero, only the last tier is unbounded, and it must be. All tiers share
 * one currency, because a price belongs to one project.
 */
final readonly class Tiers
{
    /**
     * @param non-empty-list<Tier> $tiers
     */
    private function __construct(private array $tiers) {}

    /**
     * @param list<Tier> $tiers
     */
    public static function of(array $tiers): self
    {
        if ($tiers === []) {
            throw InvalidPricing::noTiers();
        }

        $last = count($tiers) - 1;
        $previous = Quantity::zero();
        $currency = $tiers[0]->unitPrice->currency();

        foreach ($tiers as $index => $tier) {
            if ($tier->unitPrice->currency() !== $currency) {
                throw InvalidPricing::mixedCurrencies($currency, $tier->unitPrice->currency());
            }

            if ($tier->limit === null) {
                if ($index !== $last) {
                    throw InvalidPricing::unboundedTierBeforeLast($index + 1);
                }
            } elseif ($tier->limit->compareTo($previous) <= 0) {
                throw InvalidPricing::limitsMustRise($index + 1);
            } else {
                $previous = $tier->limit;
            }
        }

        if ($tiers[$last]->limit instanceof Quantity) {
            throw InvalidPricing::lastTierBounded();
        }

        return new self($tiers);
    }

    /**
     * @return non-empty-list<Tier>
     */
    public function all(): array
    {
        return $this->tiers;
    }

    public function currency(): string
    {
        return $this->tiers[0]->unitPrice->currency();
    }
}
