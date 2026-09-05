<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Seed;

/**
 * How much a seeded tenant holds (ADR-0016).
 *
 * `demo` is sized for roughly two million events over ninety days, `heavy`
 * for twenty million, `small` for a tenant that seeds in seconds — the one
 * every demo sign-up gets. Only the last `liveDays` go through the ingestion
 * API; everything older is history, loaded by the backfill, because the
 * acceptance window refuses events more than seven days old and because two
 * million requests would take longer than anyone waits.
 */
enum Profile: string
{
    case Small = 'small';
    case Demo = 'demo';
    case Heavy = 'heavy';

    public function customers(): int
    {
        return match ($this) {
            self::Small => 12,
            self::Demo => 120,
            self::Heavy => 1_000,
        };
    }

    public function historyDays(): int
    {
        return match ($this) {
            self::Small => 60,
            self::Demo, self::Heavy => 90,
        };
    }

    /**
     * Days of usage sent through the API, ending now. At most the
     * acceptance window, and small enough that the per-key rate limit does
     * not turn seeding into a wait.
     */
    public function liveDays(): int
    {
        return match ($this) {
            self::Small => 7,
            self::Demo, self::Heavy => 1,
        };
    }

    /**
     * Events an average customer sends in a day. A customer's own size, the
     * hour and the weekday move it around this. Customers join over the
     * history's first month, fifteen and a half days in on average, which
     * the rates for demo and heavy allow for.
     */
    public function eventsPerCustomerDay(): int
    {
        return match ($this) {
            self::Small => 40,
            self::Demo => 220,
            self::Heavy => 265,
        };
    }
}
