<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Http;

use Metered\Billing\Domain\Customer;
use Metered\Billing\Domain\Meter;
use Metered\Billing\Domain\Plan\Plan;
use Metered\Billing\Domain\Plan\PlanVersion;
use Metered\Billing\Domain\Plan\Price;
use Metered\Billing\Domain\Pricing\FlatFee;
use Metered\Billing\Domain\Pricing\Graduated;
use Metered\Billing\Domain\Pricing\PerUnit;
use Metered\Billing\Domain\Pricing\Tiers;
use Metered\Billing\Domain\Pricing\Volume;
use Metered\Billing\Domain\Subscription\Subscription;
use Metered\Billing\Domain\Subscription\SubscriptionPhase;
use Metered\Shared\Domain\Identifier\Uuid;

/**
 * Catalog JSON shapes. Decimals are strings, money is minor units.
 */
final class CatalogJson
{
    /**
     * @return array<string, mixed>
     */
    public static function meter(Meter $meter): array
    {
        return [
            'id' => $meter->id->value,
            'code' => $meter->code->value,
            'name' => $meter->name,
            'aggregation' => $meter->aggregation->value,
            'created_at' => $meter->definedAt->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function customer(Customer $customer): array
    {
        return [
            'id' => $customer->id->value,
            'reference' => $customer->reference->value,
            'name' => $customer->name,
            'created_at' => $customer->registeredAt->format(DATE_ATOM),
        ];
    }

    /**
     * @param list<PlanVersion> $versions
     * @param array<string, string> $meterCodes meter codes by meter id
     *
     * @return array<string, mixed>
     */
    public static function plan(Plan $plan, array $versions, array $meterCodes): array
    {
        return [
            'id' => $plan->id->value,
            'code' => $plan->code->value,
            'name' => $plan->name,
            'created_at' => $plan->createdAt->format(DATE_ATOM),
            'versions' => array_map(static fn(PlanVersion $v): array => self::version($v, $meterCodes), $versions),
        ];
    }

    /**
     * @param array<string, string> $meterCodes meter codes by meter id
     *
     * @return array<string, mixed>
     */
    public static function version(PlanVersion $version, array $meterCodes): array
    {
        return [
            'id' => $version->id->value,
            'plan_id' => $version->planId->value,
            'number' => $version->number,
            'currency' => $version->currency,
            'interval' => $version->interval->value,
            'published_at' => $version->publishedAt?->format(DATE_ATOM),
            'prices' => array_map(static fn(Price $p): array => self::price($p, $meterCodes), $version->prices),
        ];
    }

    /**
     * @param array<string, string> $meterCodes meter codes by meter id
     *
     * @return array<string, mixed>
     */
    public static function price(Price $price, array $meterCodes): array
    {
        $model = $price->model;
        $json = [
            'id' => $price->id->value,
            'meter' => $price->meterId instanceof Uuid ? $meterCodes[$price->meterId->value] ?? null : (null),
        ];

        return $json + match (true) {
            $model instanceof FlatFee => ['model' => 'flat_fee', 'amount' => $model->amount()->minorUnits()],
            $model instanceof PerUnit => ['model' => 'per_unit', 'unit_price' => (string) $model->unitPrice->toBigDecimal()],
            $model instanceof Graduated => ['model' => 'graduated', 'tiers' => self::tiers($model->tiers)],
            $model instanceof Volume => ['model' => 'volume', 'tiers' => self::tiers($model->tiers)],
            default => ['model' => 'unknown'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function subscription(Subscription $subscription): array
    {
        return [
            'id' => $subscription->id->value,
            'customer_id' => $subscription->customerId->value,
            'status' => $subscription->status->value,
            'currency' => $subscription->currency,
            'interval' => $subscription->interval->value,
            'anchor_at' => $subscription->anchorAt->format(DATE_ATOM),
            'ends_at' => $subscription->endsAt?->format(DATE_ATOM),
            'phases' => array_map(static fn(SubscriptionPhase $phase): array => [
                'plan_version_id' => $phase->planVersionId->value,
                'starts_at' => $phase->startsAt->format(DATE_ATOM),
                'ends_at' => $phase->endsAt?->format(DATE_ATOM),
            ], $subscription->phases),
        ];
    }

    /**
     * @return list<array{up_to: ?string, unit_price: string}>
     */
    private static function tiers(Tiers $tiers): array
    {
        $json = [];

        foreach ($tiers->all() as $tier) {
            $json[] = [
                'up_to' => $tier->limit === null ? null : (string) $tier->limit,
                'unit_price' => (string) $tier->unitPrice->toBigDecimal(),
            ];
        }

        return $json;
    }
}
