<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Seed;

/**
 * Seed sizes (ADR-0016): `demo` about 2M events over 90 days, `heavy` 20M,
 * `small` seconds (demo sign-ups). Only the last `liveDays` go through the
 * API; older days are bulk-loaded.
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
     * Within the acceptance window and the per-key rate limit.
     */
    public function liveDays(): int
    {
        return match ($this) {
            self::Small => 7,
            self::Demo, self::Heavy => 1,
        };
    }

    /**
     * Per average customer per day, before size, hour and weekday factors.
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
