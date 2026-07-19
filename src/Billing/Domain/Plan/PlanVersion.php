<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Plan;

use DateTimeImmutable;
use Metered\Billing\Domain\Exception\InvalidPlanVersion;
use Metered\Billing\Domain\Exception\PlanVersionLocked;
use Metered\Billing\Domain\Period\BillingInterval;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * The terms of a plan at one point in its history: a currency, a billing
 * interval, and the prices charged on it.
 *
 * A version is a draft until it is published, and immutable from then on.
 * Only a published version can be subscribed to, which is a stronger form of
 * the glossary's rule that a version in use never changes: there is no window
 * between "subscribed to" and "locked" in which it could (assumptions.md).
 */
final readonly class PlanVersion
{
    /**
     * @param list<Price> $prices
     */
    private function __construct(
        public Uuid $id,
        public TenantContext $tenant,
        public Uuid $planId,
        public int $number,
        public string $currency,
        public BillingInterval $interval,
        public array $prices,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $publishedAt,
    ) {}

    public static function draft(
        Uuid $id,
        TenantContext $tenant,
        Uuid $planId,
        int $number,
        string $currency,
        BillingInterval $interval,
        DateTimeImmutable $at,
    ): self {
        if ($number < 1) {
            throw InvalidPlanVersion::numberBelowOne($number);
        }

        // Normalised and checked against the currency table by the type that
        // will later hold every amount this version charges.
        $currency = Money::zero($currency)->currency();

        return new self($id, $tenant, $planId, $number, $currency, $interval, [], $at, null);
    }

    /**
     * @param list<Price> $prices
     *
     * @internal for the repository, rebuilding a version exactly as it was stored
     */
    public static function restore(
        Uuid $id,
        TenantContext $tenant,
        Uuid $planId,
        int $number,
        string $currency,
        BillingInterval $interval,
        array $prices,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $publishedAt,
    ): self {
        return new self($id, $tenant, $planId, $number, $currency, $interval, $prices, $createdAt, $publishedAt);
    }

    public function withPrice(Price $price): self
    {
        $this->guardDraft();

        if ($price->currency() !== $this->currency) {
            throw InvalidPlanVersion::currencyMismatch($this->currency, $price->currency());
        }

        foreach ($this->prices as $held) {
            if ($held->id->equals($price->id)) {
                throw InvalidPlanVersion::priceAlreadyHeld($price->id->value);
            }

            if ($price->meterId instanceof Uuid && $held->meterId?->equals($price->meterId) === true) {
                throw InvalidPlanVersion::meterAlreadyPriced($price->meterId->value);
            }
        }

        return $this->withPrices([...$this->prices, $price]);
    }

    public function withoutPrice(Uuid $priceId): self
    {
        $this->guardDraft();

        $remaining = array_values(array_filter(
            $this->prices,
            static fn(Price $held): bool => ! $held->id->equals($priceId),
        ));

        if (count($remaining) === count($this->prices)) {
            throw InvalidPlanVersion::noSuchPrice($priceId->value);
        }

        return $this->withPrices($remaining);
    }

    public function publish(DateTimeImmutable $at): self
    {
        $this->guardDraft();

        if ($this->prices === []) {
            throw InvalidPlanVersion::nothingToCharge();
        }

        return new self(
            $this->id,
            $this->tenant,
            $this->planId,
            $this->number,
            $this->currency,
            $this->interval,
            $this->prices,
            $this->createdAt,
            $at,
        );
    }

    public function isPublished(): bool
    {
        return $this->publishedAt instanceof DateTimeImmutable;
    }

    /**
     * @param list<Price> $prices
     */
    private function withPrices(array $prices): self
    {
        return new self(
            $this->id,
            $this->tenant,
            $this->planId,
            $this->number,
            $this->currency,
            $this->interval,
            $prices,
            $this->createdAt,
            null,
        );
    }

    private function guardDraft(): void
    {
        if ($this->isPublished()) {
            throw PlanVersionLocked::published($this->number);
        }
    }
}
