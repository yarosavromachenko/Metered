<?php

declare(strict_types=1);

use Metered\Shared\Infrastructure\Tracing\TelemetryFlush;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

/**
 * A batch processor with a delay far longer than the test, so that nothing is
 * exported unless the flush sends it.
 *
 * @return array{provider: TracerProviderInterface, exported: ArrayObject<int, mixed>}
 */
function bufferingProvider(): array
{
    /** @var ArrayObject<int, mixed> $exported */
    $exported = new ArrayObject();
    $processor = new BatchSpanProcessor(new InMemoryExporter($exported), ClockFactory::getDefault(), scheduledDelayMillis: 3_600_000);

    return ['provider' => TracerProvider::builder()->addSpanProcessor($processor)->build(), 'exported' => $exported];
}

it('sends the spans a quiet process is holding', function (): void {
    ['provider' => $provider, 'exported' => $exported] = bufferingProvider();
    $provider->getTracer('test')->spanBuilder('outbox publish')->startSpan()->end();

    expect($exported)->toHaveCount(0);

    new TelemetryFlush($provider, static fn(): int => 0)->flushIfDue();

    expect($exported)->toHaveCount(1);
});

it('sends at most once a second, however often it is asked', function (): void {
    ['provider' => $provider, 'exported' => $exported] = bufferingProvider();
    $clock = 5_000_000_000;
    $flush = new TelemetryFlush($provider, static function () use (&$clock): int {
        return $clock;
    });
    $tracer = $provider->getTracer('test');

    $flush->flushIfDue();
    $tracer->spanBuilder('request')->startSpan()->end();

    $clock += TelemetryFlush::INTERVAL_NANOSECONDS - 1;
    $flush->flushIfDue();

    expect($exported)->toHaveCount(0);

    $clock += 1;
    $flush->flushIfDue();

    expect($exported)->toHaveCount(1);
});

it('does nothing when tracing is off', function (): void {
    $calls = 0;

    new TelemetryFlush(new NoopTracerProvider(), static function () use (&$calls): int {
        return ++$calls;
    })->flushIfDue();

    // Not even the clock is read: switched off, it costs nothing.
    expect($calls)->toBe(0);
});
