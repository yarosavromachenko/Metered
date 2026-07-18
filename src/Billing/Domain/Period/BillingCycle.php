<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Period;

use DateTimeImmutable;
use DateTimeZone;
use Metered\Billing\Domain\Exception\InvalidPeriod;

/**
 * The sequence of billing periods that follows from an anchor.
 *
 * Every boundary is computed from the anchor directly — anchor plus n
 * intervals — and never from the boundary before it. That is what brings a
 * cycle anchored on 31 January back to 31 March after clamping to 28
 * February; stepping from the previous boundary would leave it stuck on the
 * 28th for good.
 *
 * All arithmetic is in UTC. A boundary is an instant, not a wall-clock time
 * somewhere, so daylight saving never moves one (ADR-0009).
 */
final readonly class BillingCycle
{
    private function __construct(
        public DateTimeImmutable $anchor,
        public BillingInterval $interval,
    ) {}

    public static function of(DateTimeImmutable $anchor, BillingInterval $interval): self
    {
        return new self($anchor->setTimezone(new DateTimeZone('UTC')), $interval);
    }

    public function periodContaining(DateTimeImmutable $instant): BillingPeriod
    {
        if ($instant < $this->anchor) {
            throw InvalidPeriod::beforeCycle($instant, $this->anchor);
        }

        // Walked from the anchor rather than guessed from the calendar: a
        // decade of monthly periods is a hundred and twenty steps, and a walk
        // has no off-by-one to get wrong.
        $index = 0;

        while ($this->boundary($index + 1) <= $instant) {
            ++$index;
        }

        return BillingPeriod::between($this->boundary($index), $this->boundary($index + 1));
    }

    /**
     * The start of the period at $index, counting the anchor's period as zero:
     * the anchor moved on by whole months, its day clamped to the length of
     * the month it lands in, its time of day kept.
     */
    private function boundary(int $index): DateTimeImmutable
    {
        $month = (int) $this->anchor->format('n') - 1 + $index * $this->interval->months();
        $year = (int) $this->anchor->format('Y') + intdiv($month, 12);
        $month = $month % 12 + 1;

        $daysInMonth = (int) $this->anchor->setDate($year, $month, 1)->format('t');

        return $this->anchor->setDate($year, $month, min((int) $this->anchor->format('j'), $daysInMonth));
    }
}
