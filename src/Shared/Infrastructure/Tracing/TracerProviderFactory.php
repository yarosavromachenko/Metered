<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Tracing;

use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Common\Util\ShutdownHandler;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\ResourceAttributes;

/**
 * Builds the tracer provider, or a no-op one when tracing is switched off.
 *
 * A no-op provider rather than a conditional at every call site: code that
 * starts a span should not have to ask whether tracing is enabled, and a
 * provider that quietly does nothing is cheaper than a branch in every method.
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
            ->setResource($this->resource())
            ->build();

        // The batch is exported when it fills or when a span ends after the
        // delay has passed. A command that finishes first — a scheduled run,
        // a worker being stopped — would take its last spans with it.
        ShutdownHandler::register($provider->shutdown(...));

        return $provider;
    }

    private function resource(): ResourceInfo
    {
        return ResourceInfoFactory::defaultResource()->merge(ResourceInfo::create(Attributes::create([
            ResourceAttributes::SERVICE_NAME => $this->serviceName,
            ResourceAttributes::DEPLOYMENT_ENVIRONMENT_NAME => $this->deploymentEnvironment,
        ])));
    }
}
