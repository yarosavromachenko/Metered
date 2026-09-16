<?php

declare(strict_types=1);

namespace Tests\Support;

use Metered\Shared\Application\Metrics\Metrics;
use Metered\Shared\Infrastructure\Metrics\OpenTelemetryMetrics;
use OpenTelemetry\API\Metrics\MeterProviderInterface as MeterProviderBinding;
use OpenTelemetry\SDK\Metrics\Data\Gauge;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\NumberDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;

/**
 * A meter that keeps what it measured in memory, so a test can read back the
 * counters and histograms the code recorded — by name and by labels, as a
 * dashboard would query them.
 */
final readonly class InMemoryMetrics
{
    public MeterProviderInterface $provider;

    public OpenTelemetryMetrics $metrics;

    private InMemoryExporter $exporter;

    private ExportingReader $reader;

    public function __construct()
    {
        // Cumulative, as the collector receives it: every read sees the totals.
        $this->exporter = new InMemoryExporter(temporality: Temporality::CUMULATIVE);
        $this->reader = new ExportingReader($this->exporter);
        $this->provider = MeterProvider::builder()->addReader($this->reader)->build();
        $this->metrics = new OpenTelemetryMetrics($this->provider);
    }

    /**
     * Swaps the application's meter for this one. Call it before anything
     * that records is resolved: a singleton built earlier keeps its meter.
     */
    public static function install(): self
    {
        $recorder = new self();

        app()->instance(MeterProviderBinding::class, $recorder->provider);
        app()->instance(Metrics::class, $recorder->metrics);

        return $recorder;
    }

    /**
     * A counter's total for one label set, 0 when it was never recorded.
     *
     * @param  array<string, string>  $labels
     */
    public function counted(string $name, array $labels = []): int|float
    {
        $point = $this->point($name, $labels, Sum::class);

        return $point instanceof NumberDataPoint ? $point->value : 0;
    }

    /**
     * A gauge's last value for one label set, null when it was not observed.
     *
     * @param  array<string, string>  $labels
     */
    public function gauge(string $name, array $labels = []): int|float|null
    {
        $point = $this->point($name, $labels, Gauge::class);

        return $point instanceof NumberDataPoint ? $point->value : null;
    }

    /**
     * How many measurements a histogram took for one label set, and their sum.
     *
     * @param  array<string, string>  $labels
     * @return array{count: int, sum: int|float}
     */
    public function histogram(string $name, array $labels = []): array
    {
        $point = $this->point($name, $labels, Histogram::class);

        return $point instanceof HistogramDataPoint
            ? ['count' => $point->count, 'sum' => $point->sum]
            : ['count' => 0, 'sum' => 0];
    }

    /**
     * @param  array<string, string>  $labels
     * @param  class-string  $type
     */
    private function point(string $name, array $labels, string $type): NumberDataPoint|HistogramDataPoint|null
    {
        foreach ($this->pointsOf($name, $type) as $point) {
            if ($point->attributes->toArray() === $labels) {
                return $point;
            }
        }

        return null;
    }

    /**
     * @param  class-string|null  $type
     * @return list<NumberDataPoint|HistogramDataPoint>
     */
    private function pointsOf(string $name, ?string $type = null): array
    {
        $this->reader->collect();
        $points = [];

        foreach ($this->exporter->collect(reset: true) as $metric) {
            if (! $metric instanceof Metric || $metric->name !== $name || ($type !== null && ! $metric->data instanceof $type)) {
                continue;
            }

            if ($metric->data instanceof Sum || $metric->data instanceof Gauge || $metric->data instanceof Histogram) {
                foreach ($metric->data->dataPoints as $point) {
                    if ($point instanceof NumberDataPoint || $point instanceof HistogramDataPoint) {
                        $points[] = $point;
                    }
                }
            }
        }

        return $points;
    }
}
