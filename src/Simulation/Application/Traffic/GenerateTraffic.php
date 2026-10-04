<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Traffic;

use Closure;
use SensitiveParameter;

/**
 * `eventsPerSecond` counts events, batched into at most five requests a
 * second. `duplicateRate` and `lateRate` (up to three days back) are shares
 * of events. Out-of-order shifts events up to ten minutes back; a burst is
 * 5x the rate for one second in fifteen.
 */
final readonly class GenerateTraffic
{
    /**
     * @param  (Closure(int, int): void)|null  $progress  told each second's number and events sent so far
     */
    public function __construct(
        #[SensitiveParameter]
        public string $token,
        public int $eventsPerSecond = 50,
        public int $seconds = 60,
        public float $duplicateRate = 0.01,
        public float $lateRate = 0.02,
        public bool $outOfOrder = false,
        public bool $burst = false,
        public int $seed = 1,
        public ?Closure $progress = null,
    ) {}
}
