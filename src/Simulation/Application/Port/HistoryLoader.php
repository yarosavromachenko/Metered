<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Port;

use DateTimeImmutable;
use Metered\Simulation\Application\Seed\SimulatedEvent;

/**
 * Bulk-loads events and aggregates older than the acceptance window directly
 * into the tables, then `usage:reconcile` checks them (ADR-0016).
 */
interface HistoryLoader
{
    /**
     * @param  iterable<SimulatedEvent>  $events  every one inside [from, to), one customer's after another's
     */
    public function load(string $token, iterable $events, DateTimeImmutable $from, DateTimeImmutable $to): LoadedHistory;
}
