<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Pricing;

use Brick\Math\BigDecimal;
use Metered\Billing\Domain\Exception\InvalidPricing;
use Metered\Shared\Domain\Money\UnitPrice;
use Metered\Shared\Domain\Quantity\Quantity;

/**
 * Validated on construction: limits strictly increasing from zero, exactly the
 * last tier unbounded, one currency.
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

    /**
     * "up to 1000", "over 1000 up to 5000", "over 5000".
     *
     * @return non-empty-list<string>
     */
    public function labels(): array
    {
        $labels = [];
        $floor = null;

        foreach ($this->tiers as $tier) {
            $labels[] = match (true) {
                $tier->limit instanceof Quantity => $floor instanceof Quantity
                    ? sprintf('over %s up to %s', self::plain($floor->toBigDecimal()), self::plain($tier->limit->toBigDecimal()))
                    : sprintf('up to %s', self::plain($tier->limit->toBigDecimal())),
                $floor instanceof Quantity => sprintf('over %s', self::plain($floor->toBigDecimal())),
                default => 'every unit',
            };

            $floor = $tier->limit;
        }

        return $labels;
    }

    /**
     * 1000, not 1000.000000.
     */
    public static function plain(BigDecimal $value): string
    {
        return (string) $value->strippedOfTrailingZeros();
    }

    public static function price(UnitPrice $price): string
    {
        return sprintf('%s %s', self::plain($price->toBigDecimal()), $price->currency());
    }
}
