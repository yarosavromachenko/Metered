<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Port;

use DateTimeImmutable;
use Metered\Simulation\Application\Seed\SimulatedEvent;

/**
 * The bulk path for history (ADR-0016): events and their aggregates written
 * straight into the tables, in the shape the consumer would have left them,
 * then checked by `usage:reconcile`. Faster than the API by orders of
 * magnitude, and the only way to load anything older than the acceptance
 * window at all.
 */
interface HistoryLoader
{
    /**
     * @param  iterable<SimulatedEvent>  $events  every one inside [from, to), one customer's after another's
     */
    public function load(string $token, iterable $events, DateTimeImmutable $from, DateTimeImmutable $to): LoadedHistory;
}
