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
 * OTLP to the collector (ADR-0019), or no-op when telemetry is off. There is
 * no export timer; TelemetryFlush collects at idle points and at shutdown.
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

        // Cumulative, as Prometheus expects.
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
