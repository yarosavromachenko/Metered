<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use OpenTelemetry\API\Trace\Span;

/**
 * Stamps a log line with the trace and span it was written in.
 *
 * Without a log store beside the traces, this is what joins the two: a line
 * in `docker compose logs` carries the id to paste into Tempo, and a span in
 * Tempo carries the id to grep for. Lines written outside any span are left
 * as they are.
 */
final readonly class TraceContextProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $context = Span::getCurrent()->getContext();

        if (! $context->isValid()) {
            return $record;
        }

        return $record->with(extra: [
            ...$record->extra,
            'trace_id' => $context->getTraceId(),
            'span_id' => $context->getSpanId(),
        ]);
    }
}
