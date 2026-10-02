<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Metrics;

/**
 * Buckets come from the scale; the SDK defaults are sized for milliseconds.
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
