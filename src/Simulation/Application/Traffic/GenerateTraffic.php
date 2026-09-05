<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Traffic;

use Closure;
use SensitiveParameter;

/**
 * Live usage against one tenant, through the ingestion API, for a while.
 *
 * `eventsPerSecond` is events, not requests: they are batched so that no
 * more than five requests a second leave, well inside a key's rate limit.
 * The rates are shares of the events sent: `duplicateRate` resends an event
 * already sent, `lateRate` reports something from up to three days ago. Out
 * of order moves every event up to ten minutes back, so arrival order and
 * time order disagree; a burst quintuples the rate for one second in fifteen.
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
