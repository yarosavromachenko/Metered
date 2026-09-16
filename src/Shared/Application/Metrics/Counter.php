<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Metrics;

/**
 * A count that only goes up: requests answered, events rejected.
 *
 * The name is OpenTelemetry's, with dots; Prometheus shows it with
 * underscores and a `_total` suffix (docs/observability.md).
 */
final readonly class Counter
{
    public function __construct(
        public string $name,
        public string $unit,
        public string $description,
    ) {}
}
