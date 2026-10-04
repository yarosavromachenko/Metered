<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Period;

use DateTimeImmutable;
use DateTimeZone;
use Metered\Billing\Domain\Exception\InvalidPeriod;

/**
 * Each boundary is anchor + n intervals, never derived from the previous one,
 * so a cycle anchored on 31 January is back on 31 March after 28 February.
 * UTC throughout (ADR-0009).
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

        // Walk from the anchor; ten years monthly is only 120 steps.
        $index = 0;

        while ($this->boundary($index + 1) <= $instant) {
            ++$index;
        }

        return BillingPeriod::between($this->boundary($index), $this->boundary($index + 1));
    }

    /**
     * Anchor + $index intervals, the day clamped to the month's length, time kept.
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
