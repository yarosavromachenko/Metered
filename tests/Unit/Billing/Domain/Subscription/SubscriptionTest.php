<?php

declare(strict_types=1);

use Metered\Billing\Domain\Exception\SubscriptionChangeRefused;
use Metered\Billing\Domain\Period\BillingInterval;
use Metered\Billing\Domain\Period\BillingPeriod;
use Metered\Billing\Domain\Plan\PlanVersion;
use Metered\Billing\Domain\Plan\Price;
use Metered\Billing\Domain\Pricing\FlatFee;
use Metered\Billing\Domain\Subscription\Subscription;
use Metered\Billing\Domain\Subscription\SubscriptionPhase;
use Metered\Billing\Domain\Subscription\SubscriptionStatus;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Tenant\TenantContext;
use Symfony\Component\Clock\MockClock;

const STARTER = '01924b7c-0000-7000-8000-000000000d01';
const PRO = '01924b7c-0000-7000-8000-000000000d02';

function subscriptionTenant(string $project = '01924b7c-0000-7000-8000-000000000d91'): TenantContext
{
    return new TenantContext(Uuid::fromString('01924b7c-0000-7000-8000-000000000d90'), Uuid::fromString($project));
}

function version(
    string $id,
    bool $published = true,
    string $currency = 'EUR',
    BillingInterval $interval = BillingInterval::Month,
    ?TenantContext $tenant = null,
): PlanVersion {
    $version = PlanVersion::draft(
        Uuid::fromString($id),
        $tenant ?? subscriptionTenant(),
        Uuid::fromString('01924b7c-0000-7000-8000-000000000d80'),
        1,
        $currency,
        $interval,
        new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
    )->withPrice(Price::fixed(Uuid::fromString('01924b7c-0000-7000-8000-000000000d81'), FlatFee::of(Money::ofMinorUnits(1000, $currency))));

    return $published ? $version->publish(new DateTimeImmutable('2026-01-01T00:00:00+00:00')) : $version;
}

function subscribe(MockClock $clock, ?PlanVersion $version = null): Subscription
{
    return Subscription::start(
        Uuid::fromString('01924b7c-0000-7000-8000-000000000d70'),
        subscriptionTenant(),
        Uuid::fromString('01924b7c-0000-7000-8000-000000000d71'),
        $version ?? version(STARTER),
        $clock->now(),
    );
}

/**
 * @return list<string> each phase as "version from → to"
 */
function phasesOf(Subscription $subscription): array
{
    return array_map(
        static fn(SubscriptionPhase $phase): string => sprintf(
            '%s %s → %s',
            $phase->planVersionId->value === STARTER ? 'starter' : 'pro',
            $phase->startsAt->format('Y-m-d H:i'),
            $phase->endsAt?->format('Y-m-d H:i') ?? '…',
        ),
        $subscription->phases,
    );
}

it('starts on a published version, anchored at the moment it starts', function (): void {
    $clock = new MockClock('2026-01-31 14:00:00', 'UTC');
    $subscription = subscribe($clock);

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->anchorAt->format(DATE_ATOM))->toBe('2026-01-31T14:00:00+00:00')
        ->and($subscription->customerId->value)->toBe('01924b7c-0000-7000-8000-000000000d71')
        ->and($subscription->currency)->toBe('EUR')
        ->and($subscription->interval)->toBe(BillingInterval::Month)
        ->and($subscription->endsAt)->toBeNull()
        ->and(phasesOf($subscription))->toBe(['starter 2026-01-31 14:00 → …']);
});

it('refuses to start on a draft, or on another project\'s version', function (): void {
    $clock = new MockClock('2026-01-31 14:00:00', 'UTC');

    expect(static fn(): Subscription => subscribe($clock, version(STARTER, published: false)))
        ->toThrow(SubscriptionChangeRefused::class, 'version 1 is a draft')
        ->and(static fn(): Subscription => subscribe($clock, version(STARTER, tenant: subscriptionTenant('01924b7c-0000-7000-8000-000000000d99'))))
        ->toThrow(SubscriptionChangeRefused::class, 'belongs to another project');
});

it('bills month by month from its anchor as time passes', function (): void {
    $clock = new MockClock('2026-01-31 14:00:00', 'UTC');
    $subscription = subscribe($clock);

    expect((string) $subscription->periodAt($clock->now()))->toBe('[2026-01-31T14:00:00Z, 2026-02-28T14:00:00Z)');

    $clock->modify('+28 days');
    expect((string) $subscription->periodAt($clock->now()))->toBe('[2026-02-28T14:00:00Z, 2026-03-31T14:00:00Z)');

    $clock->modify('+11 months');
    expect((string) $subscription->periodAt($clock->now()))->toBe('[2026-12-31T14:00:00Z, 2027-01-31T14:00:00Z)');
});

it('changes plan at the end of the current period, never in the middle of one', function (): void {
    $clock = new MockClock('2026-01-31 14:00:00', 'UTC');
    $subscription = subscribe($clock);

    $clock->modify('2026-02-15 09:00:00');
    $changed = $subscription->changePlan(version(PRO), $clock->now());

    expect(phasesOf($changed))->toBe([
        'starter 2026-01-31 14:00 → 2026-02-28 14:00',
        'pro 2026-02-28 14:00 → …',
    ])
        ->and($changed->versionAt(new DateTimeImmutable('2026-02-28T13:59:59+00:00'))?->value)->toBe(STARTER)
        ->and($changed->versionAt(new DateTimeImmutable('2026-02-28T14:00:00+00:00'))?->value)->toBe(PRO)
        ->and(phasesOf($subscription))->toBe(['starter 2026-01-31 14:00 → …']);
});

it('refuses a plan change that would change what a period means', function (PlanVersion $to, string $message): void {
    $clock = new MockClock('2026-01-31 14:00:00', 'UTC');

    expect(static fn(): Subscription => subscribe($clock)->changePlan($to, $clock->now()))
        ->toThrow(SubscriptionChangeRefused::class, $message);
})->with([
    'the version it is already on' => [version(STARTER), 'already on that version'],
    'a draft' => [version(PRO, published: false), 'version 1 is a draft'],
    'another currency' => [version(PRO, currency: 'USD'), 'billed in EUR'],
    'another interval' => [version(PRO, interval: BillingInterval::Year), 'billed every month'],
    'another project' => [version(PRO, tenant: subscriptionTenant('01924b7c-0000-7000-8000-000000000d99')), 'belongs to another project'],
]);

it('refuses a second change while one is already waiting for the period to end', function (): void {
    $clock = new MockClock('2026-01-31 14:00:00', 'UTC');
    $changed = subscribe($clock)->changePlan(version(PRO), $clock->now());

    expect(static fn(): Subscription => $changed->changePlan(version(STARTER), $clock->now()))
        ->toThrow(SubscriptionChangeRefused::class, 'already scheduled for 2026-02-28');
});

it('allows another change once the scheduled one has taken effect', function (): void {
    $clock = new MockClock('2026-01-31 14:00:00', 'UTC');
    $changed = subscribe($clock)->changePlan(version(PRO), $clock->now());

    $clock->modify('2026-03-10 00:00:00');

    expect(phasesOf($changed->changePlan(version(STARTER), $clock->now())))->toBe([
        'starter 2026-01-31 14:00 → 2026-02-28 14:00',
        'pro 2026-02-28 14:00 → 2026-03-31 14:00',
        'starter 2026-03-31 14:00 → …',
    ]);
});

it('cancels at the end of the period, which also drops a change that would have started then', function (): void {
    $clock = new MockClock('2026-01-31 14:00:00', 'UTC');
    $subscription = subscribe($clock)->changePlan(version(PRO), $clock->now());

    $clock->modify('2026-02-20 00:00:00');
    $canceling = $subscription->cancelAtPeriodEnd($clock->now());

    expect($canceling->status)->toBe(SubscriptionStatus::PendingCancellation)
        ->and($canceling->endsAt?->format(DATE_ATOM))->toBe('2026-02-28T14:00:00+00:00')
        ->and(phasesOf($canceling))->toBe(['starter 2026-01-31 14:00 → 2026-02-28 14:00']);
});

it('lapses into canceled once its end has passed, and not a moment before', function (): void {
    $clock = new MockClock('2026-02-20 00:00:00', 'UTC');
    $canceling = subscribe($clock)->cancelAtPeriodEnd($clock->now());
    $end = $canceling->endsAt ?? throw new LogicException('no end');

    expect($canceling->lapse($end->modify('-1 microsecond'))->status)->toBe(SubscriptionStatus::PendingCancellation)
        ->and($canceling->lapse($end)->status)->toBe(SubscriptionStatus::Canceled)
        ->and(subscribe($clock)->lapse($end)->status)->toBe(SubscriptionStatus::Active);
});

it('cancels immediately, cutting the current phase short', function (): void {
    $clock = new MockClock('2026-01-31 14:00:00', 'UTC');
    $subscription = subscribe($clock)->changePlan(version(PRO), $clock->now());

    $clock->modify('2026-02-10 08:00:00');
    $canceled = $subscription->cancelNow($clock->now());

    expect($canceled->status)->toBe(SubscriptionStatus::Canceled)
        ->and($canceled->endsAt?->format(DATE_ATOM))->toBe('2026-02-10T08:00:00+00:00')
        ->and(phasesOf($canceled))->toBe(['starter 2026-01-31 14:00 → 2026-02-10 08:00']);
});

it('accepts nothing more once canceled', function (Closure $change): void {
    /** @var Closure(Subscription, DateTimeImmutable): Subscription $change */
    $clock = new MockClock('2026-01-31 14:00:00', 'UTC');
    $canceled = subscribe($clock)->cancelNow($clock->now());

    expect(static fn(): Subscription => $change($canceled, $clock->now()))
        ->toThrow(SubscriptionChangeRefused::class, 'is canceled');
})->with([
    'a plan change' => [static fn(Subscription $s, DateTimeImmutable $now): Subscription => $s->changePlan(version(PRO), $now)],
    'a cancellation at period end' => [static fn(Subscription $s, DateTimeImmutable $now): Subscription => $s->cancelAtPeriodEnd($now)],
    'an immediate cancellation' => [static fn(Subscription $s, DateTimeImmutable $now): Subscription => $s->cancelNow($now)],
]);

it('refuses a plan change once a cancellation is pending, but still takes an immediate one', function (): void {
    $clock = new MockClock('2026-01-31 14:00:00', 'UTC');
    $canceling = subscribe($clock)->cancelAtPeriodEnd($clock->now());

    expect(static fn(): Subscription => $canceling->changePlan(version(PRO), $clock->now()))
        ->toThrow(SubscriptionChangeRefused::class, 'is pending cancellation')
        ->and(static fn(): Subscription => $canceling->cancelAtPeriodEnd($clock->now()))
        ->toThrow(SubscriptionChangeRefused::class, 'is pending cancellation')
        ->and($canceling->cancelNow($clock->now())->status)->toBe(SubscriptionStatus::Canceled);
});

it('keeps a record of a subscription canceled the instant it started', function (): void {
    $clock = new MockClock('2026-01-31 14:00:00', 'UTC');
    $canceled = subscribe($clock)->cancelNow($clock->now());

    expect(phasesOf($canceled))->toBe(['starter 2026-01-31 14:00 → 2026-01-31 14:00'])
        ->and($canceled->versionAt($clock->now()))->toBeNull();
});

it('names no version outside its lifetime', function (): void {
    $clock = new MockClock('2026-01-31 14:00:00', 'UTC');
    $canceled = subscribe($clock)->cancelNow(new DateTimeImmutable('2026-02-10T08:00:00+00:00'));

    expect($canceled->versionAt(new DateTimeImmutable('2026-01-31T13:59:59+00:00')))->toBeNull()
        ->and($canceled->versionAt(new DateTimeImmutable('2026-02-10T08:00:00+00:00')))->toBeNull()
        ->and($canceled->versionAt(new DateTimeImmutable('2026-02-10T07:59:59+00:00'))?->value)->toBe(STARTER);
});

/**
 * @param list<BillingPeriod> $periods
 *
 * @return list<string>
 */
function periodsOf(array $periods): array
{
    return array_map(static fn(BillingPeriod $period): string => (string) $period, $periods);
}

it('lists the periods that have ended, walking the month-end clamp', function (): void {
    $clock = new MockClock('2026-01-31 14:00:00', 'UTC');
    $subscription = subscribe($clock);

    expect(periodsOf($subscription->periodsEndedBy($subscription->anchorAt, new DateTimeImmutable('2026-05-01T00:00:00Z'))))->toBe([
        '[2026-01-31T14:00:00Z, 2026-02-28T14:00:00Z)',
        '[2026-02-28T14:00:00Z, 2026-03-31T14:00:00Z)',
        '[2026-03-31T14:00:00Z, 2026-04-30T14:00:00Z)',
    ]);
});

it('lists a period only once it has ended, to the microsecond', function (): void {
    $subscription = subscribe(new MockClock('2026-01-31 14:00:00', 'UTC'));

    expect($subscription->periodsEndedBy($subscription->anchorAt, new DateTimeImmutable('2026-02-28T13:59:59.999999Z')))->toBe([])
        ->and(periodsOf($subscription->periodsEndedBy($subscription->anchorAt, new DateTimeImmutable('2026-02-28T14:00:00Z'))))
        ->toBe(['[2026-01-31T14:00:00Z, 2026-02-28T14:00:00Z)']);
});

it('starts where the last invoice stopped, never before it', function (): void {
    $subscription = subscribe(new MockClock('2026-01-31 14:00:00', 'UTC'));
    $endedBy = new DateTimeImmutable('2026-05-01T00:00:00Z');

    expect(periodsOf($subscription->periodsEndedBy(new DateTimeImmutable('2026-03-31T14:00:00Z'), $endedBy)))
        ->toBe(['[2026-03-31T14:00:00Z, 2026-04-30T14:00:00Z)'])
        ->and(periodsOf($subscription->periodsEndedBy(new DateTimeImmutable('2026-03-01T00:00:00Z'), $endedBy)))
        ->toBe(['[2026-03-31T14:00:00Z, 2026-04-30T14:00:00Z)'])
        ->and($subscription->periodsEndedBy(new DateTimeImmutable('2026-04-30T14:00:00Z'), $endedBy))->toBe([]);
});

it('bills the last period of a canceled subscription up to the cancellation', function (): void {
    $clock = new MockClock('2026-01-31 14:00:00', 'UTC');
    $subscription = subscribe($clock);
    $clock->modify('+50 days');
    $canceled = $subscription->cancelNow($clock->now());

    expect(periodsOf($canceled->periodsEndedBy($canceled->anchorAt, new DateTimeImmutable('2027-01-01T00:00:00Z'))))->toBe([
        '[2026-01-31T14:00:00Z, 2026-02-28T14:00:00Z)',
        '[2026-02-28T14:00:00Z, 2026-03-22T14:00:00Z)',
    ])
        ->and(periodsOf($canceled->periodsEndedBy($canceled->anchorAt, new DateTimeImmutable('2026-03-22T13:00:00Z'))))
        ->toBe(['[2026-01-31T14:00:00Z, 2026-02-28T14:00:00Z)']);
});

it('ends on a boundary when canceled at period end, adding no empty period', function (): void {
    $clock = new MockClock('2026-01-31 14:00:00', 'UTC');
    $subscription = subscribe($clock);
    $clock->modify('+10 days');
    $pending = $subscription->cancelAtPeriodEnd($clock->now());

    expect(periodsOf($pending->periodsEndedBy($pending->anchorAt, new DateTimeImmutable('2027-01-01T00:00:00Z'))))
        ->toBe(['[2026-01-31T14:00:00Z, 2026-02-28T14:00:00Z)']);
});

it('has nothing to bill when canceled the instant it started', function (): void {
    $clock = new MockClock('2026-01-31 14:00:00', 'UTC');
    $canceled = subscribe($clock)->cancelNow($clock->now());

    expect($canceled->periodsEndedBy($canceled->anchorAt, new DateTimeImmutable('2027-01-01T00:00:00Z')))->toBe([]);
});

it('starts from the anchor when asked from before the subscription existed', function (): void {
    $subscription = subscribe(new MockClock('2026-01-31 14:00:00', 'UTC'));

    expect(periodsOf($subscription->periodsEndedBy(new DateTimeImmutable('2025-12-01T00:00:00Z'), new DateTimeImmutable('2026-03-01T00:00:00Z'))))
        ->toBe(['[2026-01-31T14:00:00Z, 2026-02-28T14:00:00Z)']);
});
