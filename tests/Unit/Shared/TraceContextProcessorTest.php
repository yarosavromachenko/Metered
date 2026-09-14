<?php

declare(strict_types=1);

use Metered\Shared\Infrastructure\Logging\TraceContextProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use OpenTelemetry\SDK\Trace\TracerProvider;

function logRecord(): LogRecord
{
    return new LogRecord(new DateTimeImmutable(), 'app', Level::Warning, 'Failed to publish an outbox message.', extra: ['host' => 'relay-1']);
}

it('stamps a line with the trace and span it was written in', function (): void {
    $span = TracerProvider::builder()->build()->getTracer('test')->spanBuilder('outbox publish')->startSpan();
    $scope = $span->activate();

    try {
        $record = new TraceContextProcessor()(logRecord());
    } finally {
        $scope->detach();
        $span->end();
    }

    expect($record->extra)->toBe([
        'host' => 'relay-1',
        'trace_id' => $span->getContext()->getTraceId(),
        'span_id' => $span->getContext()->getSpanId(),
    ]);
});

it('leaves a line written outside any span as it was', function (): void {
    expect(new TraceContextProcessor()(logRecord())->extra)->toBe(['host' => 'relay-1']);
});
