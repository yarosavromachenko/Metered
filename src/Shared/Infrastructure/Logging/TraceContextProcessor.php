<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use OpenTelemetry\API\Trace\Span;

/**
 * Adds trace and span ids to log records written inside a span, so logs and
 * Tempo traces can be matched.
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
