<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Metrics;

use Metered\Shared\Application\Metrics\Gauge;
use Metered\Shared\Application\Metrics\GaugeReading;
use Metered\Shared\Application\Metrics\GaugeSource;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads every gauge source and reports what it read.
 *
 * Observable gauges rather than values set once: each collection reports
 * exactly the readings of the last pass, so an endpoint that was removed or a
 * breaker that closed stops being reported instead of repeating its last
 * value forever. A source that fails is logged and reports nothing for that
 * pass; the others are not held back by it.
 */
final class GaugeObserver
{
    private readonly MeterInterface $meter;

    /** @var array<string, list<GaugeReading>> */
    private array $latest = [];

    /** @var array<string, true> */
    private array $registered = [];

    /**
     * @param  list<GaugeSource>  $sources
     */
    public function __construct(
        private readonly array $sources,
        MeterProviderInterface $meterProvider,
        private readonly LoggerInterface $logger,
    ) {
        $this->meter = $meterProvider->getMeter('metered');
    }

    /**
     * @return int how many readings this pass took
     */
    public function observe(): int
    {
        $latest = [];
        $taken = 0;

        foreach ($this->sources as $source) {
            try {
                $readings = $source->read();
            } catch (Throwable $exception) {
                $this->logger->warning('A gauge source could not be read.', [
                    'source' => $source::class,
                    'exception' => $exception,
                ]);

                continue;
            }

            foreach ($readings as $reading) {
                $this->register($reading->gauge);
                $latest[$reading->gauge->name][] = $reading;
                ++$taken;
            }
        }

        $this->latest = $latest;

        return $taken;
    }

    private function register(Gauge $gauge): void
    {
        if (isset($this->registered[$gauge->name])) {
            return;
        }

        $name = $gauge->name;
        $this->meter->createObservableGauge(
            $name,
            $gauge->unit,
            $gauge->description,
            [],
            function (ObserverInterface $observer) use ($name): void {
                foreach ($this->latest[$name] ?? [] as $reading) {
                    $observer->observe($reading->value, $reading->labels);
                }
            },
        );

        $this->registered[$name] = true;
    }
}
