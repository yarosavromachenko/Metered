<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Metrics;

/**
 * Modules register sources under {@see self::TAG}; only `metrics:observe`
 * reads them, on a timer.
 */
interface GaugeSource
{
    public const string TAG = 'metered.gauge_sources';

    /**
     * @return list<GaugeReading>
     */
    public function read(): array;
}
