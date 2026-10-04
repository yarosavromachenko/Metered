<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Subscription;

use DateTimeImmutable;
use DateTimeZone;
use Metered\Billing\Domain\Exception\SubscriptionChangeRefused;
use Metered\Billing\Domain\Period\BillingCycle;
use Metered\Billing\Domain\Period\BillingInterval;
use Metered\Billing\Domain\Period\BillingPeriod;
use Metered\Billing\Domain\Plan\PlanVersion;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * Phases, each pinned to one plan version. A plan change takes effect at the
 * end of the current period, so each period has one version (no proration).
 * Currency and interval cannot change.
 */
final readonly class Subscription
{
    /**
     * @param non-empty-list<SubscriptionPhase> $phases
     */
    private function __construct(
        public Uuid $id,
        public TenantContext $tenant,
        public Uuid $customerId,
        public DateTimeImmutable $anchorAt,
        public string $currency,
        public BillingInterval $interval,
        public SubscriptionStatus $status,
        public array $phases,
        public ?DateTimeImmutable $endsAt,
    ) {}

    public static function start(
        Uuid $id,
        TenantContext $tenant,
        Uuid $customerId,
        PlanVersion $version,
        DateTimeImmutable $at,
    ): self {
        self::guardSubscribable($tenant, $version);

        $anchor = $at->setTimezone(new DateTimeZone('UTC'));

        return new self(
            $id,
            $tenant,
            $customerId,
            $anchor,
            $version->currency,
            $version->interval,
            SubscriptionStatus::Active,
            [new SubscriptionPhase($version->id, $anchor, null)],
            null,
        );
    }

    /**
     * @param non-empty-list<SubscriptionPhase> $phases
     *
     * @internal for the repository
     */
    public static function restore(
        Uuid $id,
        TenantContext $tenant,
        Uuid $customerId,
        DateTimeImmutable $anchorAt,
        string $currency,
        BillingInterval $interval,
        SubscriptionStatus $status,
        array $phases,
        ?DateTimeImmutable $endsAt,
    ): self {
        return new self($id, $tenant, $customerId, $anchorAt, $currency, $interval, $status, $phases, $endsAt);
    }

    public function cycle(): BillingCycle
    {
        return BillingCycle::of($this->anchorAt, $this->interval);
    }

    public function periodAt(DateTimeImmutable $instant): BillingPeriod
    {
        return $this->cycle()->periodContaining($instant);
    }

    /**
     * Periods starting at or after $from and ended by $endedBy. The last
     * period of an ended subscription is cut at its end date.
     *
     * @return list<BillingPeriod>
     */
    public function periodsEndedBy(DateTimeImmutable $from, DateTimeImmutable $endedBy): array
    {
        $periods = [];
        $cursor = max($from, $this->anchorAt);

        while (! $this->endsAt instanceof DateTimeImmutable || $cursor < $this->endsAt) {
            $period = $this->periodAt($cursor);
            $end = min($period->end, $this->endsAt ?? $period->end);

            if ($end > $endedBy) {
                break;
            }

            // A period containing $from is already invoiced.
            if ($period->start >= $from) {
                $periods[] = BillingPeriod::between($period->start, $end);
            }

            $cursor = $period->end;
        }

        return $periods;
    }

    /**
     * Null outside the subscription's lifetime.
     */
    public function versionAt(DateTimeImmutable $instant): ?Uuid
    {
        foreach ($this->phases as $phase) {
            if ($phase->covers($instant)) {
                return $phase->planVersionId;
            }
        }

        return null;
    }

    public function changePlan(PlanVersion $to, DateTimeImmutable $now): self
    {
        $this->guardActive();
        self::guardSubscribable($this->tenant, $to);

        $current = $this->phases[count($this->phases) - 1];

        if ($current->startsAt > $now) {
            throw SubscriptionChangeRefused::changeScheduled($current->startsAt);
        }

        if ($current->planVersionId->equals($to->id)) {
            throw SubscriptionChangeRefused::sameVersion();
        }

        if ($to->currency !== $this->currency) {
            throw SubscriptionChangeRefused::currencyChanged($this->currency, $to->currency);
        }

        if ($to->interval !== $this->interval) {
            throw SubscriptionChangeRefused::intervalChanged($this->interval->value, $to->interval->value);
        }

        $boundary = $this->periodAt($now)->end;
        $phases = $this->phases;
        $phases[count($phases) - 1] = $current->endingAt($boundary);
        $phases[] = new SubscriptionPhase($to->id, $boundary, null);

        return $this->with(SubscriptionStatus::Active, $phases, null);
    }

    public function cancelAtPeriodEnd(DateTimeImmutable $now): self
    {
        $this->guardActive();

        return $this->endAt($this->periodAt($now)->end, SubscriptionStatus::PendingCancellation);
    }

    public function cancelNow(DateTimeImmutable $now): self
    {
        if ($this->status === SubscriptionStatus::Canceled) {
            throw SubscriptionChangeRefused::notActive($this->status->value);
        }

        return $this->endAt($now, SubscriptionStatus::Canceled);
    }

    /**
     * Called by the period close.
     */
    public function lapse(DateTimeImmutable $now): self
    {
        if ($this->status !== SubscriptionStatus::PendingCancellation || $this->endsAt > $now) {
            return $this;
        }

        return $this->with(SubscriptionStatus::Canceled, $this->phases, $this->endsAt);
    }

    /**
     * Drops phases starting at or after $end and cuts the current one.
     */
    private function endAt(DateTimeImmutable $end, SubscriptionStatus $status): self
    {
        // The first phase is kept, possibly as an empty `[anchor, anchor)`.
        $kept = array_values(array_filter(
            $this->phases,
            static fn(SubscriptionPhase $phase, int $index): bool => $index === 0 || $phase->startsAt < $end,
            ARRAY_FILTER_USE_BOTH,
        ));

        $last = count($kept) - 1;
        $kept[$last] = $kept[$last]->endingAt($end);

        return $this->with($status, $kept, $end);
    }

    /**
     * @param array<int, SubscriptionPhase> $phases
     */
    private function with(SubscriptionStatus $status, array $phases, ?DateTimeImmutable $endsAt): self
    {
        /** @var non-empty-list<SubscriptionPhase> $phases */
        return new self(
            $this->id,
            $this->tenant,
            $this->customerId,
            $this->anchorAt,
            $this->currency,
            $this->interval,
            $status,
            $phases,
            $endsAt,
        );
    }

    private function guardActive(): void
    {
        if ($this->status !== SubscriptionStatus::Active) {
            throw SubscriptionChangeRefused::notActive($this->status->value);
        }
    }

    private static function guardSubscribable(TenantContext $tenant, PlanVersion $version): void
    {
        if (! $version->tenant->equals($tenant)) {
            throw SubscriptionChangeRefused::otherProject();
        }

        if (! $version->isPublished()) {
            throw SubscriptionChangeRefused::draftVersion($version->number);
        }
    }
}
