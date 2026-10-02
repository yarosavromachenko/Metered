<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Metrics;

/**
 * Named with dots; Prometheus exports it with underscores and `_total`
 * (docs/observability.md).
 */
final readonly class Counter
{
    public function __construct(
        public string $name,
        public string $unit,
        public string $description,
    ) {}
}
