<?php

declare(strict_types=1);

namespace Metered\Simulation\Infrastructure\Laravel;

use Closure;
use Metered\Simulation\Application\Port\Waiter;

final class PollingWaiter implements Waiter
{
    private const int POLL_MICROSECONDS = 500_000;

    public function until(Closure $condition, int $timeoutSeconds): ?float
    {
        $start = hrtime(true);

        while (true) {
            if ($condition() === true) {
                return (hrtime(true) - $start) / 1e9;
            }

            if ((hrtime(true) - $start) / 1e9 >= $timeoutSeconds) {
                return null;
            }

            usleep(self::POLL_MICROSECONDS);
        }
    }
}
