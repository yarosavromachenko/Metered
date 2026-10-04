<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Plan;

use Metered\Billing\Domain\Exception\InvalidPricing;
use Metered\Billing\Domain\Pricing\PricingModel;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Quantity\Quantity;

/**
 * A pricing model plus, for usage-based models, the meter. Separate
 * constructors prevent a usage price without a meter and vice versa.
 */
final readonly class Price
{
    private function __construct(
        public Uuid $id,
        public PricingModel $model,
        public ?Uuid $meterId,
    ) {}

    public static function metered(Uuid $id, PricingModel $model, Uuid $meterId): self
    {
        if (! $model->isUsageBased()) {
            throw InvalidPricing::meterOnFixedCharge();
        }

        return new self($id, $model, $meterId);
    }

    public static function fixed(Uuid $id, PricingModel $model): self
    {
        if ($model->isUsageBased()) {
            throw InvalidPricing::usageWithoutMeter();
        }

        return new self($id, $model, null);
    }

    public function charge(Quantity $quantity): Money
    {
        return $this->model->charge($quantity);
    }

    public function currency(): string
    {
        return $this->model->currency();
    }
}
