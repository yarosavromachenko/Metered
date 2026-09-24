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
 * A customer's subscription, as a sequence of phases each pinned to one plan
 * version.
 *
 * History is appended to, never rewritten. A plan change closes the current
 * phase at the end of the period and opens the next one there — so every
 * period is billed on exactly one version, and there is nothing to prorate
 * (proration is not implemented). For the same reason a change may not
 * alter the currency or the interval: either would change what a period is,
 * and that is a new subscription.
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
     * @internal for the repository, rebuilding a subscription exactly as it was stored
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
     * The periods that start at or after $from and have ended by $endedBy, in
     * order — what is left to invoice once $from is where the last invoice
     * stopped.
     *
     * Each is a period of the cycle, except the last one of a subscription
     * that has ended, which stops at the end: a subscription canceled on the
     * 10th is billed up to the 10th and not a day past it.
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

            // A $from inside a period means that period was invoiced already,
            // or began before the subscription did; either way not again.
            if ($period->start >= $from) {
                $periods[] = BillingPeriod::between($period->start, $end);
            }

            $cursor = $period->end;
        }

        return $periods;
    }

    /**
     * The plan version that prices $instant, or null outside the
     * subscription's life.
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
     * A pending cancellation becomes a cancellation once its end has passed.
     * Asked by the period close; anything else is returned unchanged.
     */
    public function lapse(DateTimeImmutable $now): self
    {
        if ($this->status !== SubscriptionStatus::PendingCancellation || $this->endsAt > $now) {
            return $this;
        }

        return $this->with(SubscriptionStatus::Canceled, $this->phases, $this->endsAt);
    }

    /**
     * Ends the subscription at $end: phases that would only have started then
     * or later are dropped, and the one running at $end is cut short there.
     */
    private function endAt(DateTimeImmutable $end, SubscriptionStatus $status): self
    {
        // The first phase is always kept: a subscription canceled at the very
        // instant it started keeps it as `[anchor, anchor)`, a record that it
        // existed and covered nothing.
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
