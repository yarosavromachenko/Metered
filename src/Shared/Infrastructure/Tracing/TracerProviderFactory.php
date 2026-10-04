<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Tracing;

use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Common\Util\ShutdownHandler;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

/**
 * No-op provider when tracing is off, so call sites need no checks.
 */
final readonly class TracerProviderFactory
{
    public function __construct(
        private bool $enabled,
        private string $serviceName,
        private string $endpoint,
        private string $deploymentEnvironment,
    ) {}

    public function make(): TracerProviderInterface
    {
        if (! $this->enabled) {
            return new NoopTracerProvider();
        }

        $exporter = new SpanExporter(
            new OtlpHttpTransportFactory()->create($this->endpoint . '/v1/traces', 'application/x-protobuf'),
        );

        $provider = TracerProvider::builder()
            ->addSpanProcessor(new BatchSpanProcessor($exporter, ClockFactory::getDefault()))
            ->setResource(TelemetryResource::describe($this->serviceName, $this->deploymentEnvironment))
            ->build();

        // Flush on shutdown, or a short command loses its last batch.
        ShutdownHandler::register($provider->shutdown(...));

        return $provider;
    }
}
