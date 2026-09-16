<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Metrics;

use Metered\Shared\Infrastructure\Tracing\TelemetryResource;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\SDK\Common\Util\ShutdownHandler;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;

/**
 * Builds the meter provider: OTLP to the collector, which Prometheus scrapes
 * (ADR-0019), or a no-op one when telemetry is switched off.
 *
 * Nothing exports on a timer — PHP has no thread for it. The reader is
 * collected at the idle points of long-running processes (TelemetryFlush)
 * and at shutdown.
 */
final readonly class MeterProviderFactory
{
    public function __construct(
        private bool $enabled,
        private string $serviceName,
        private string $endpoint,
        private string $deploymentEnvironment,
    ) {}

    public function make(): MeterProviderInterface
    {
        if (! $this->enabled) {
            return new NoopMeterProvider();
        }

        // Cumulative: each process reports its totals since it started, the
        // shape Prometheus stores. A restart is a reset rate() handles.
        $exporter = new MetricExporter(
            new OtlpHttpTransportFactory()->create($this->endpoint . '/v1/metrics', 'application/x-protobuf'),
            Temporality::CUMULATIVE,
        );

        $provider = MeterProvider::builder()
            ->setResource(TelemetryResource::describe($this->serviceName, $this->deploymentEnvironment))
            ->addReader(new ExportingReader($exporter))
            ->build();

        ShutdownHandler::register($provider->shutdown(...));

        return $provider;
    }
}
