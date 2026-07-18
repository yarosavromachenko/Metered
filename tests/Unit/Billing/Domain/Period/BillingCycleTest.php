<?php

declare(strict_types=1);

use Metered\Billing\Domain\Exception\InvalidPeriod;
use Metered\Billing\Domain\Period\BillingCycle;
use Metered\Billing\Domain\Period\BillingInterval;
use Metered\Billing\Domain\Period\BillingPeriod;
use Symfony\Component\Clock\MockClock;

function utc(string $instant): DateTimeImmutable
{
    return new DateTimeImmutable($instant, new DateTimeZone('UTC'));
}

function periodOf(BillingCycle $cycle, string $at): string
{
    return (string) $cycle->periodContaining(utc($at));
}

it('bills monthly from the anchor, keeping its time of day', function (string $at, string $expected): void {
    $cycle = BillingCycle::of(utc('2026-03-15 09:30:00'), BillingInterval::Month);

    expect(periodOf($cycle, $at))->toBe($expected);
})->with([
    'the anchor itself opens the first period' => ['2026-03-15 09:30:00', '[2026-03-15T09:30:00Z, 2026-04-15T09:30:00Z)'],
    'inside the first period' => ['2026-04-01 00:00:00', '[2026-03-15T09:30:00Z, 2026-04-15T09:30:00Z)'],
    'a microsecond before the boundary' => ['2026-04-15 09:29:59.999999', '[2026-03-15T09:30:00Z, 2026-04-15T09:30:00Z)'],
    'the boundary belongs to the next period' => ['2026-04-15 09:30:00', '[2026-04-15T09:30:00Z, 2026-05-15T09:30:00Z)'],
    'many periods later' => ['2027-08-20 12:00:00', '[2027-08-15T09:30:00Z, 2027-09-15T09:30:00Z)'],
]);

it('clamps an anchor late in the month to the month\'s last day, and then returns to it', function (string $at, string $expected): void {
    $cycle = BillingCycle::of(utc('2026-01-31 00:00:00'), BillingInterval::Month);

    expect(periodOf($cycle, $at))->toBe($expected);
})->with([
    '31 January to 28 February' => ['2026-02-10', '[2026-01-31T00:00:00Z, 2026-02-28T00:00:00Z)'],
    'and back to 31 March, not 28' => ['2026-03-01', '[2026-02-28T00:00:00Z, 2026-03-31T00:00:00Z)'],
    'a thirty-day month clamps to its 30th' => ['2026-04-15', '[2026-03-31T00:00:00Z, 2026-04-30T00:00:00Z)'],
    'and May has its 31st again' => ['2026-05-15', '[2026-04-30T00:00:00Z, 2026-05-31T00:00:00Z)'],
    'a leap year clamps to the 29th' => ['2028-02-10', '[2028-01-31T00:00:00Z, 2028-02-29T00:00:00Z)'],
]);

it('rolls a monthly cycle over the year', function (): void {
    $cycle = BillingCycle::of(utc('2026-10-31 18:00:00'), BillingInterval::Month);

    expect(periodOf($cycle, '2026-12-31 23:59:59'))->toBe('[2026-12-31T18:00:00Z, 2027-01-31T18:00:00Z)')
        ->and(periodOf($cycle, '2027-01-01 00:00:00'))->toBe('[2026-12-31T18:00:00Z, 2027-01-31T18:00:00Z)')
        ->and(periodOf($cycle, '2027-02-01 00:00:00'))->toBe('[2027-01-31T18:00:00Z, 2027-02-28T18:00:00Z)');
});

it('bills yearly, clamping a leap day to 28 February and returning to it', function (string $at, string $expected): void {
    $cycle = BillingCycle::of(utc('2028-02-29 12:00:00'), BillingInterval::Year);

    expect(periodOf($cycle, $at))->toBe($expected);
})->with([
    'the first year' => ['2028-06-01', '[2028-02-29T12:00:00Z, 2029-02-28T12:00:00Z)'],
    'a common year ends on the 28th' => ['2029-06-01', '[2029-02-28T12:00:00Z, 2030-02-28T12:00:00Z)'],
    'the next leap year has its 29th back' => ['2032-03-01', '[2032-02-29T12:00:00Z, 2033-02-28T12:00:00Z)'],
]);

it('keeps boundaries on the same UTC instant across daylight saving changes', function (): void {
    // Europe/Berlin moves its clocks on 29 March and 25 October 2026. A cycle
    // anchored in UTC does not notice: every boundary is at 10:00Z, and a
    // period across a change is simply a day count, never 23 or 25 hours off.
    $cycle = BillingCycle::of(utc('2026-02-28 10:00:00'), BillingInterval::Month);

    expect(periodOf($cycle, '2026-03-29 12:00:00'))->toBe('[2026-03-28T10:00:00Z, 2026-04-28T10:00:00Z)')
        ->and(periodOf($cycle, '2026-10-25 12:00:00'))->toBe('[2026-09-28T10:00:00Z, 2026-10-28T10:00:00Z)');
});

it('reads an anchor given in another zone as the instant it names', function (): void {
    // 00:30 in Berlin on 1 April is 22:30 UTC on 31 March. The cycle is that
    // instant's, clamped by UTC's calendar — not a local midnight on the 1st.
    $cycle = BillingCycle::of(new DateTimeImmutable('2026-04-01 00:30:00', new DateTimeZone('Europe/Berlin')), BillingInterval::Month);

    expect(periodOf($cycle, '2026-04-10'))->toBe('[2026-03-31T22:30:00Z, 2026-04-30T22:30:00Z)')
        ->and($cycle->anchor->getTimezone()->getName())->toBe('UTC');
});

it('leaves no gap and no overlap between consecutive periods, a year of days in', function (): void {
    // Time travel: walk the clock a day at a time through a leap year from an
    // awkward anchor and hold invariant 5 — the end of one period is the start
    // of the next — at every step.
    $clock = new MockClock('2027-12-31 23:00:00', 'UTC');
    $cycle = BillingCycle::of($clock->now(), BillingInterval::Month);
    $previous = $cycle->periodContaining($clock->now());
    $seen = 1;

    for ($day = 0; $day < 366; ++$day) {
        $clock->modify('+1 day');
        $current = $cycle->periodContaining($clock->now());

        expect($current->contains($clock->now()))->toBeTrue();

        if (! $current->equals($previous)) {
            expect($current->start)->toEqual($previous->end);
            $previous = $current;
            ++$seen;
        }
    }

    expect($seen)->toBe(13);
});

it('refuses an instant before the cycle begins', function (): void {
    $cycle = BillingCycle::of(utc('2026-03-15 00:00:00'), BillingInterval::Month);

    expect(static fn(): BillingPeriod => $cycle->periodContaining(utc('2026-03-14 23:59:59')))
        ->toThrow(InvalidPeriod::class, 'before the billing cycle begins');
});
