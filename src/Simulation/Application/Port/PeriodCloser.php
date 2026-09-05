<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Port;

/**
 * Closes every period that has ended, now rather than on the scheduler's
 * next turn — what `billing:close-periods --sync` does for an operator.
 */
interface PeriodCloser
{
    public function closeDue(): void;
}
