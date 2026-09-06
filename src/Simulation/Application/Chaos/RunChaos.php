<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Chaos;

use Closure;

final readonly class RunChaos
{
    /**
     * @param  int  $events  usage events sent, or subscriptions started, depending on the scenario
     * @param  (Closure(string): void)|null  $progress
     */
    public function __construct(
        public Scenario $scenario,
        public int $events = 2_000,
        public ?Closure $progress = null,
    ) {}
}
