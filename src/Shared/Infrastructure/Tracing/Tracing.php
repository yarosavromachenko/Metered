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
 * The one place that knows how trace context enters and leaves this
 * application.
 *
 * Context crosses four asynchronous boundaries here — HTTP into a Redis
 * stream, stream into a batch consumer, database into the outbox relay, relay
 * into a queue worker — and each is a place where a trace is normally lost.
 * Keeping the carrier logic in a single object is what stops each of those
 * hops inventing its own header name.
 */
final readonly class Tracing
{
    public function __construct(
        private TracerProviderInterface $tracerProvider,
        private TextMapPropagatorInterface $propagator,
    ) {}

    /**
     * Tracing that records nothing, for the places that are built without
     * the container: a span started here costs nothing and goes nowhere.
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
     * The current context as W3C headers, ready to travel with a message.
     *
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
     * Restores the context a message was published in.
     *
     * @param  array<string, string>  $carrier
     */
    public function extract(array $carrier): ContextInterface
    {
        return $this->propagator->extract($carrier, ArrayAccessGetterSetter::getInstance());
    }

    /**
     * The span a message was published from, to link to rather than to
     * continue. Invalid when the message carried no context.
     *
     * @param  array<string, string>  $carrier
     */
    public function spanContextFrom(array $carrier): SpanContextInterface
    {
        return Span::fromContext($this->extract($carrier))->getContext();
    }

    /**
     * The trace a span belongs to, for logging beside it.
     */
    public function traceIdOf(SpanInterface $span): string
    {
        return $span->getContext()->getTraceId();
    }

    public function currentContext(): ContextInterface
    {
        return Context::getCurrent();
    }
}
