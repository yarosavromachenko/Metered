<?php

declare(strict_types=1);

namespace Metered\Webhooks\Domain\Delivery;

use DateTimeImmutable;

/**
 * How long a delivery waits after each failed attempt: ten attempts, nine
 * waits — 1m, 5m, 30m, 1h, 2h, 4h, 8h, 12h, 24h — about two and a half days
 * in all (ADR-0011).
 *
 * Each wait is moved by up to a fifth either way. Without that, every endpoint
 * that failed during one incident retries in the same second, and the
 * recovery is a thundering herd of its own making.
 */
final class RetrySchedule
{
    public const int MAX_ATTEMPTS = 10;

    /** Plus or minus this many thousandths of the wait. */
    public const int JITTER_PERMILLE = 200;


    /**
     * When to try again after the $failedAttempts-th failure, or null when
     * that was the last attempt.
     *
     * @param int $jitter a draw from [-JITTER_PERMILLE, JITTER_PERMILLE]
     */
    public static function nextAttemptAt(int $failedAttempts, DateTimeImmutable $failedAt, int $jitter): ?DateTimeImmutable
    {
        if ($failedAttempts >= self::MAX_ATTEMPTS) {
            return null;
        }

        $wait = self::waits()[max(0, $failedAttempts - 1)];
        $jitter = max(-self::JITTER_PERMILLE, min(self::JITTER_PERMILLE, $jitter));

        return $failedAt->modify(sprintf('+%d seconds', intdiv($wait * (1000 + $jitter), 1000)));
    }

    /**
     * Seconds to wait after the first, second, … failed attempt. Computed
     * rather than a constant, so that mutation testing can reach the numbers:
     * a literal list is not code that runs (docs/testing.md).
     *
     * @return list<int>
     */
    private static function waits(): array
    {
        return array_map(static fn(int $minutes): int => $minutes * 60, [1, 5, 30, 60, 120, 240, 480, 720, 1440]);
    }
}
