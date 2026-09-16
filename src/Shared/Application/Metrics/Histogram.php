<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Metrics;

/**
 * A distribution of measurements — durations, sizes — kept as counts per
 * bucket, from which percentiles are read.
 *
 * The scale fixes the buckets where the answers matter: the SDK's defaults
 * are shaped for milliseconds, and a duration in seconds would fall into the
 * first two of them.
 */
final readonly class Histogram
{
    public function __construct(
        public string $name,
        public string $unit,
        public string $description,
        public Scale $scale,
    ) {}

    public static function duration(string $name, string $description): self
    {
        return new self($name, 's', $description, Scale::Seconds);
    }
}
