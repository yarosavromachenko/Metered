<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Metrics;

/**
 * Something whose current state is worth a gauge: a stream's depth, the
 * outbox's lag, which breakers are open.
 *
 * Modules contribute sources under {@see self::TAG}. One process,
 * `metrics:observe`, reads them all on a timer; asking every worker to read
 * the same depth would multiply the queries and the series for nothing.
 */
interface GaugeSource
{
    public const string TAG = 'metered.gauge_sources';

    /**
     * @return list<GaugeReading>
     */
    public function read(): array;
}
