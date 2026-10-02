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
 * Observable gauges report only the last pass, so a removed endpoint stops
 * being reported. A failing source is logged and skipped for that pass.
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
