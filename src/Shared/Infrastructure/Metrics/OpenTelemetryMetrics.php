<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Metrics;

use Metered\Shared\Application\Metrics\Counter;
use Metered\Shared\Application\Metrics\Histogram;
use Metered\Shared\Application\Metrics\Metrics;
use Metered\Shared\Application\Metrics\Scale;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;

/**
 * Instruments are created on first use and cached for the process lifetime.
 */
final class OpenTelemetryMetrics implements Metrics
{
    private const int NANOSECONDS_PER_SECOND = 1_000_000_000;

    private readonly MeterInterface $meter;

    /** @var array<string, CounterInterface> */
    private array $counters = [];

    /** @var array<string, HistogramInterface> */
    private array $histograms = [];

    public function __construct(MeterProviderInterface $meterProvider)
    {
        $this->meter = $meterProvider->getMeter('metered');
    }

    public function add(Counter $counter, int $increment = 1, array $labels = []): void
    {
        $this->counters[$counter->name] ??= $this->meter->createCounter($counter->name, $counter->unit, $counter->description);
        $this->counters[$counter->name]->add($increment, $labels);
    }

    public function record(Histogram $histogram, int $value, array $labels = []): void
    {
        $this->histograms[$histogram->name] ??= $this->meter->createHistogram(
            $histogram->name,
            $histogram->unit,
            $histogram->description,
            ['ExplicitBucketBoundaries' => $this->buckets($histogram->scale)],
        );

        $this->histograms[$histogram->name]->record(
            $histogram->scale === Scale::Seconds ? $value / self::NANOSECONDS_PER_SECOND : $value,
            $labels,
        );
    }

    /**
     * @return list<float|int>
     */
    private function buckets(Scale $scale): array
    {
        return match ($scale) {
            Scale::Seconds => [0.001, 0.0025, 0.005, 0.01, 0.025, 0.05, 0.1, 0.15, 0.25, 0.5, 1, 2.5, 5, 10, 30, 60],
            Scale::Count => [1, 2, 5, 10, 25, 50, 100, 250, 500, 1000],
        };
    }
}
