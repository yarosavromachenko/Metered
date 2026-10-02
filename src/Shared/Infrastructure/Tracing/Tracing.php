<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Tracing;

use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;

/**
 * W3C trace context in and out of messages, for the async hops: HTTP to the
 * stream, stream to the consumer, outbox to the relay, relay to the queue.
 */
final readonly class Tracing
{
    public function __construct(
        private TracerProviderInterface $tracerProvider,
        private TextMapPropagatorInterface $propagator,
    ) {}

    /**
     * For code built without the container.
     */
    public static function disabled(): self
    {
        return new self(new NoopTracerProvider(), TraceContextPropagator::getInstance());
    }

    public function tracer(): TracerInterface
    {
        return $this->tracerProvider->getTracer('metered');
    }

    /**
     * @return array<string, string>
     */
    public function carrier(?ContextInterface $context = null): array
    {
        $carrier = [];
        $this->propagator->inject($carrier, ArrayAccessGetterSetter::getInstance(), $context);

        /** @var array<string, string> $carrier */
        return $carrier;
    }

    /**
     * @param  array<string, string>  $carrier
     */
    public function extract(array $carrier): ContextInterface
    {
        return $this->propagator->extract($carrier, ArrayAccessGetterSetter::getInstance());
    }

    /**
     * Span to link to; invalid when the message has no context.
     *
     * @param  array<string, string>  $carrier
     */
    public function spanContextFrom(array $carrier): SpanContextInterface
    {
        return Span::fromContext($this->extract($carrier))->getContext();
    }

    public function traceIdOf(SpanInterface $span): string
    {
        return $span->getContext()->getTraceId();
    }

    public function currentContext(): ContextInterface
    {
        return Context::getCurrent();
    }
}
