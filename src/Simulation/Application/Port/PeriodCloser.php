<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Port;

/**
 * `billing:close-periods --sync`.
 */
interface PeriodCloser
{
    public function closeDue(): void;
}
