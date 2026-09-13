<?php

declare(strict_types=1);

namespace Tests\Support;

use ArrayObject;
use Metered\Shared\Infrastructure\Tracing\QueueTracing;
use Metered\Shared\Infrastructure\Tracing\Tracing;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\TracerProviderInterface as TracerProviderBinding;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

/**
 * A tracer that keeps its spans in memory, so a test can assert on what was
 * recorded instead of on what was sent somewhere.
 */
final class InMemoryTracing
{
    /** @var ArrayObject<int, ImmutableSpan> */
    public ArrayObject $spans;

    public readonly Tracing $tracing;

    public readonly TracerProviderInterface $provider;

    public function __construct()
    {
        /** @var ArrayObject<int, ImmutableSpan> $storage */
        $storage = new ArrayObject();
        $this->spans = $storage;

        $this->provider = TracerProvider::builder()
            ->addSpanProcessor(new SimpleSpanProcessor(new InMemoryExporter($storage)))
            ->build();

        $this->tracing = new Tracing($this->provider, TraceContextPropagator::getInstance());
    }

    /**
     * Swaps the application's tracer for one that records in memory. The
     * queue hooks look their instance up when they fire, so replacing it is
     * enough for queued jobs to be recorded too.
     *
     * Call it before anything that traces is resolved: a singleton built
     * earlier keeps the tracer it was built with.
     */
    public static function install(): self
    {
        $recorder = new self();

        app()->instance(TracerProviderBinding::class, $recorder->provider);
        app()->instance(Tracing::class, $recorder->tracing);
        app()->instance(QueueTracing::class, new QueueTracing($recorder->tracing));

        return $recorder;
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

    /**
     * Every finished span called $name, in the order they ended.
     *
     * @return list<ImmutableSpan>
     */
    public function allNamed(string $name): array
    {
        return array_values(array_filter($this->finished(), static fn(ImmutableSpan $span): bool => $span->getName() === $name));
    }
}
