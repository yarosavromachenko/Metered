<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Port;

use Closure;

interface Waiter
{
    /**
     * @param  Closure(): bool  $condition
     * @return float|null seconds it took, or null if it never held
     */
    public function until(Closure $condition, int $timeoutSeconds): ?float;
}
