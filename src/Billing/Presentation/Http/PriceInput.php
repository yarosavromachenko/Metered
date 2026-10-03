<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Http;

use Metered\Billing\Domain\Pricing\FlatFee;
use Metered\Billing\Domain\Pricing\Graduated;
use Metered\Billing\Domain\Pricing\PerUnit;
use Metered\Billing\Domain\Pricing\PricingModel;
use Metered\Billing\Domain\Pricing\Tier;
use Metered\Billing\Domain\Pricing\Tiers;
use Metered\Billing\Domain\Pricing\Volume;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Money\UnitPrice;
use Metered\Shared\Domain\Quantity\Quantity;

/**
 * Builds a pricing model from a validated `prices[]` entry; domain errors
 * (bad tier table, too many decimals) become 422.
 */
final class PriceInput
{
    /**
     * The rules for one entry, keyed under $prefix (`prices.*`).
     *
     * @return array<string, list<string>>
     */
    public static function rules(string $prefix): array
    {
        return [
            $prefix => ['required', 'array'],
            $prefix . '.model' => ['required', 'string', 'in:flat_fee,per_unit,graduated,volume'],
            // A meter code; required by every model but the flat fee.
            $prefix . '.meter' => ['required_unless:' . $prefix . '.model,flat_fee', 'prohibited_if:' . $prefix . '.model,flat_fee', 'string', 'max:64'],
            $prefix . '.amount' => ['required_if:' . $prefix . '.model,flat_fee', 'integer', 'min:0'],
            // A decimal string, eight places at most.
            $prefix . '.unit_price' => ['required_if:' . $prefix . '.model,per_unit', 'string', 'max:32'],
            $prefix . '.tiers' => ['required_if:' . $prefix . '.model,graduated,volume', 'array', 'min:1', 'max:50'],
            $prefix . '.tiers.*.up_to' => ['present', 'nullable', 'string', 'max:32'],
            $prefix . '.tiers.*.unit_price' => ['required', 'string', 'max:32'],
        ];
    }

    /**
     * @param array<array-key, mixed> $entry
     */
    public static function model(array $entry, string $currency): PricingModel
    {
        return match ($entry['model'] ?? null) {
            'flat_fee' => FlatFee::of(Money::ofMinorUnits(self::int($entry['amount'] ?? null), $currency)),
            'per_unit' => PerUnit::at(UnitPrice::fromString(self::string($entry['unit_price'] ?? null), $currency)),
            'graduated' => Graduated::over(self::tiers($entry['tiers'] ?? null, $currency)),
            default => Volume::over(self::tiers($entry['tiers'] ?? null, $currency)),
        };
    }

    private static function tiers(mixed $tiers, string $currency): Tiers
    {
        $built = [];

        foreach (is_array($tiers) ? $tiers : [] as $tier) {
            $tier = is_array($tier) ? $tier : [];
            $price = UnitPrice::fromString(self::string($tier['unit_price'] ?? null), $currency);
            $limit = $tier['up_to'] ?? null;

            $built[] = $limit === null ? Tier::unbounded($price) : Tier::upTo(Quantity::fromString(self::string($limit)), $price);
        }

        return Tiers::of($built);
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }
}
