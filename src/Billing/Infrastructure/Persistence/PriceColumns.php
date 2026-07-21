<?php

declare(strict_types=1);

namespace Metered\Billing\Infrastructure\Persistence;

use JsonException;
use LogicException;
use Metered\Billing\Domain\Plan\Price;
use Metered\Billing\Domain\Pricing\FlatFee;
use Metered\Billing\Domain\Pricing\Graduated;
use Metered\Billing\Domain\Pricing\PerUnit;
use Metered\Billing\Domain\Pricing\PricingModel;
use Metered\Billing\Domain\Pricing\Tier;
use Metered\Billing\Domain\Pricing\Tiers;
use Metered\Billing\Domain\Pricing\Volume;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Money\UnitPrice;
use Metered\Shared\Domain\Quantity\Quantity;
use Metered\Shared\Infrastructure\Persistence\RowReader;
use RuntimeException;

/**
 * Translates a price to the columns of the `prices` table and back.
 *
 * Decimals travel as strings in both directions — the unit price column, and
 * each tier's limit and price inside the JSON — so nothing passes through a
 * float on the way to or from PostgreSQL.
 */
final class PriceColumns
{
    /**
     * @return array{model: string, currency: string, meter_id: ?string, flat_amount: ?int, unit_price: ?string, tiers: ?string}
     */
    public static function from(Price $price): array
    {
        $model = $price->model;
        $columns = [
            'currency' => $model->currency(),
            'meter_id' => $price->meterId?->value,
            'flat_amount' => null,
            'unit_price' => null,
            'tiers' => null,
        ];

        return match (true) {
            $model instanceof FlatFee => ['model' => 'flat_fee', 'flat_amount' => $model->amount()->minorUnits()] + $columns,
            $model instanceof PerUnit => ['model' => 'per_unit', 'unit_price' => (string) $model->unitPrice->toBigDecimal()] + $columns,
            $model instanceof Graduated => ['model' => 'graduated', 'tiers' => self::encodeTiers($model->tiers)] + $columns,
            $model instanceof Volume => ['model' => 'volume', 'tiers' => self::encodeTiers($model->tiers)] + $columns,
            default => throw new LogicException(sprintf('No columns for the pricing model %s.', $model::class)),
        };
    }

    /**
     * @param array<array-key, mixed> $row
     */
    public static function toPrice(array $row): Price
    {
        $id = Uuid::fromString(RowReader::string($row['id'] ?? null, 'id'));
        $model = self::toModel($row);

        return $model->isUsageBased()
            ? Price::metered($id, $model, Uuid::fromString(RowReader::string($row['meter_id'] ?? null, 'meter_id')))
            : Price::fixed($id, $model);
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private static function toModel(array $row): PricingModel
    {
        $currency = RowReader::string($row['currency'] ?? null, 'currency');

        return match (RowReader::string($row['model'] ?? null, 'model')) {
            'flat_fee' => FlatFee::of(Money::ofMinorUnits(RowReader::int($row['flat_amount'] ?? null, 'flat_amount'), $currency)),
            'per_unit' => PerUnit::at(UnitPrice::fromString(RowReader::string($row['unit_price'] ?? null, 'unit_price'), $currency)),
            'graduated' => Graduated::over(self::decodeTiers($row['tiers'] ?? null, $currency)),
            'volume' => Volume::over(self::decodeTiers($row['tiers'] ?? null, $currency)),
            default => throw new RuntimeException('Column "model" holds a pricing model nothing can read.'),
        };
    }

    private static function encodeTiers(Tiers $tiers): string
    {
        $encoded = [];

        foreach ($tiers->all() as $tier) {
            $encoded[] = [
                'up_to' => $tier->limit instanceof Quantity ? (string) $tier->limit : null,
                'unit_price' => (string) $tier->unitPrice->toBigDecimal(),
            ];
        }

        try {
            return json_encode($encoded, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new LogicException('Tiers could not be encoded.', 0, $e);
        }
    }

    private static function decodeTiers(mixed $value, string $currency): Tiers
    {
        $tiers = [];

        foreach (RowReader::jsonObject($value, 'tiers') as $index => $tier) {
            if (! is_array($tier)) {
                throw new RuntimeException(sprintf('Column "tiers" holds a tier %s that is not an object.', $index));
            }

            $price = UnitPrice::fromString(RowReader::string($tier['unit_price'] ?? null, 'tiers.unit_price'), $currency);
            $limit = $tier['up_to'] ?? null;

            $tiers[] = $limit === null
                ? Tier::unbounded($price)
                : Tier::upTo(Quantity::fromString(RowReader::string($limit, 'tiers.up_to')), $price);
        }

        return Tiers::of($tiers);
    }
}
