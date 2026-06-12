<?php

declare(strict_types=1);

namespace Tests\Support;

use ArrayObject;
use Metered\Shared\Infrastructure\Tracing\Tracing;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

/**
 * A tracer that keeps its spans in memory, so a test can assert on what was
 * recorded instead of on what was sent somewhere.
 */
final class InMemoryTracing
{
    /** @var ArrayObject<int, ImmutableSpan> */
    public ArrayObject $spans;

    public readonly Tracing $tracing;

    public function __construct()
    {
        /** @var ArrayObject<int, ImmutableSpan> $storage */
        $storage = new ArrayObject();
        $this->spans = $storage;

        $provider = TracerProvider::builder()
            ->addSpanProcessor(new SimpleSpanProcessor(new InMemoryExporter($storage)))
            ->build();

        $this->tracing = new Tracing($provider, TraceContextPropagator::getInstance());
    }

    /**
     * @return list<ImmutableSpan>
     */
    public function finished(): array
    {
        return array_values($this->spans->getArrayCopy());
    }

    public function named(string $name): ?ImmutableSpan
    {
        foreach ($this->finished() as $span) {
            if ($span->getName() === $name) {
                return $span;
            }
        }

        return null;
    }
}
