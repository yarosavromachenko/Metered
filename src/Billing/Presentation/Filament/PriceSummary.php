<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Filament;

use Metered\Billing\Domain\Pricing\FlatFee;
use Metered\Billing\Domain\Pricing\Graduated;
use Metered\Billing\Domain\Pricing\PerUnit;
use Metered\Billing\Domain\Pricing\Tiers;
use Metered\Billing\Domain\Pricing\Volume;
use Metered\Billing\Infrastructure\Eloquent\Price;
use Metered\Billing\Infrastructure\Persistence\PriceColumns;
use Metered\Shared\Domain\Quantity\Quantity;

/**
 * One readable line per price for the version list.
 */
final class PriceSummary
{
    public static function of(Price $row): string
    {
        $price = PriceColumns::toPrice($row->getAttributes());
        $model = $price->model;
        $meter = $row->meter->code ?? 'a meter';

        return match (true) {
            $model instanceof FlatFee => sprintf('Flat fee %s per period', $model->amount()),
            $model instanceof PerUnit => sprintf('%s: %s %s per unit', $meter, $model->unitPrice->toBigDecimal()->strippedOfTrailingZeros(), $model->currency()),
            $model instanceof Graduated => sprintf('%s: graduated — %s', $meter, self::tiers($model->tiers)),
            $model instanceof Volume => sprintf('%s: volume — %s', $meter, self::tiers($model->tiers)),
            default => 'An unknown pricing model',
        };
    }

    private static function tiers(Tiers $tiers): string
    {
        $parts = [];

        foreach ($tiers->all() as $tier) {
            $parts[] = sprintf(
                '%s at %s',
                $tier->limit instanceof Quantity ? 'up to ' . $tier->limit->toBigDecimal()->strippedOfTrailingZeros() : 'beyond',
                $tier->unitPrice->toBigDecimal()->strippedOfTrailingZeros(),
            );
        }

        return implode(', ', $parts) . ' ' . $tiers->currency();
    }
}
