<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Port;

use Closure;

interface Waiter
{
    /**
     * Asks the condition again until it holds or the time runs out.
     *
     * @param  Closure(): bool  $condition
     * @return float|null seconds it took, or null if it never held
     */
    public function until(Closure $condition, int $timeoutSeconds): ?float;
}
