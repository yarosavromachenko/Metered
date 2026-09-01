<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Seed;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Who the seeded tenant bills.
 *
 * Mostly ordinary customers spread over the plans and over the first month of
 * the history, so each has several closed periods. The first few are there on
 * purpose (ADR-0016): anchored on the 29th, 30th and 31st, so the month-end
 * clamp shows in real invoices; one who never uses anything; one who changes
 * plan; one who cancels.
 */
final readonly class Roster
{
    private const array ADJECTIVES = ['Northern', 'Blue', 'Quiet', 'Bright', 'Iron', 'Silver', 'Rapid', 'Open', 'Little', 'Grand', 'Clear', 'Red'];

    private const array NOUNS = ['Harbor', 'Pixel', 'Summit', 'Orchard', 'Signal', 'Anchor', 'Forge', 'Meadow', 'Beacon', 'Circuit', 'Lantern', 'Canyon'];

    private const array SUFFIXES = ['Labs', 'Systems', 'Analytics', 'Studio', 'Logistics', 'Health', 'Cloud', 'Retail'];

    /** Share of customers per plan, in parts of twenty. */
    private const array PLAN_MIX = ['starter' => 8, 'growth' => 6, 'scale' => 2, 'payg' => 4];

    public function __construct(private Noise $noise) {}

    /**
     * @return list<SeededCustomer>
     */
    public function customers(Profile $profile, DateTimeImmutable $now): array
    {
        $historyStart = $now->setTimezone(new DateTimeZone('UTC'))->sub(new DateInterval(sprintf('P%dD', $profile->historyDays())));
        $customers = [];

        for ($i = 0; $i < $profile->customers(); ++$i) {
            $reference = sprintf('cus_%04d', $i + 1);
            $size = 0.25 + 1.5 * $this->noise->unit($reference . '|size');

            $customers[] = match ($i) {
                0, 1, 2 => new SeededCustomer($reference, $this->name($i), 'growth', $this->lastDayNumbered(29 + $i, $historyStart, $now), $size),
                3 => new SeededCustomer($reference, $this->name($i), 'growth', $this->ordinaryStart($reference, $historyStart), $size, silent: true),
                4 => new SeededCustomer($reference, $this->name($i), 'starter', $this->ordinaryStart($reference, $historyStart), $size, switchesTo: 'growth'),
                5 => new SeededCustomer($reference, $this->name($i), 'payg', $this->ordinaryStart($reference, $historyStart), $size, cancels: true),
                default => new SeededCustomer($reference, $this->name($i), $this->plan($reference), $this->ordinaryStart($reference, $historyStart), $size),
            };
        }

        return $customers;
    }

    private function name(int $i): string
    {
        return sprintf(
            '%s %s %s',
            self::ADJECTIVES[$i % count(self::ADJECTIVES)],
            self::NOUNS[intdiv($i, count(self::ADJECTIVES)) % count(self::NOUNS)],
            self::SUFFIXES[$i % count(self::SUFFIXES)],
        );
    }

    private function plan(string $reference): string
    {
        $slot = $this->noise->between($reference . '|plan', 0, array_sum(self::PLAN_MIX) - 1);

        foreach (self::PLAN_MIX as $plan => $share) {
            if ($slot < $share) {
                return $plan;
            }

            $slot -= $share;
        }

        return 'starter';
    }

    /**
     * Somewhere in the first month of the history, at some hour of the day.
     */
    private function ordinaryStart(string $reference, DateTimeImmutable $historyStart): DateTimeImmutable
    {
        return $historyStart
            ->setTime(0, 0)
            ->add(new DateInterval(sprintf('P%dD', $this->noise->between($reference . '|day', 1, 30))))
            ->add(new DateInterval(sprintf('PT%dM', $this->noise->between($reference . '|minute', 0, 24 * 60 - 1))));
    }

    /**
     * The end of a first monthly period, clamped to a shorter month the way
     * billing periods are: 31 January is followed by 28 February.
     */
    private function monthLater(DateTimeImmutable $at): DateTimeImmutable
    {
        $next = $at->modify('first day of next month');
        $day = min((int) $at->format('j'), (int) $next->format('t'));

        return $next->setDate((int) $next->format('Y'), (int) $next->format('n'), $day);
    }

    /**
     * The latest day numbered $day inside the history whose first period has
     * already ended, at ten in the morning: a start on the 29th, 30th or 31st
     * of the month. February has none of those, so this walks back far enough
     * to find one.
     */
    private function lastDayNumbered(int $day, DateTimeImmutable $historyStart, DateTimeImmutable $now): DateTimeImmutable
    {
        $candidate = $historyStart->setTime(10, 0);

        for ($month = $now->modify('first day of this month'); $month >= $historyStart->modify('first day of this month'); $month = $month->modify('-1 month')) {
            if ((int) $month->format('t') >= $day) {
                $at = $month->setDate((int) $month->format('Y'), (int) $month->format('n'), $day)->setTime(10, 0);

                if ($at >= $historyStart && $this->monthLater($at) <= $now) {
                    return $at;
                }
            }
        }

        return $candidate;
    }
}
