<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Tracing;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;

/**
 * Carries the trace across the queue.
 *
 * This is the hop that is usually lost. A job is created in one process and
 * run in another, minutes later, so unless the context travels inside the
 * payload the worker starts a brand new trace and the question "why was this
 * webhook late" has no answer that spans both halves.
 *
 * The carrier goes in under its own key rather than as a job property: a
 * payload written by one deployment is read by the next, and a job class that
 * gained a constructor argument would otherwise fail to unserialise.
 */
final class QueueTracing
{
    public const string PAYLOAD_KEY = 'metered_trace';

    private ?SpanInterface $span = null;

    private ?ScopeInterface $scope = null;

    public function __construct(private readonly Tracing $tracing) {}

    public function register(Dispatcher $events): void
    {
        Queue::createPayloadUsing(fn(): array => [self::PAYLOAD_KEY => $this->tracing->carrier()]);

        $events->listen(JobProcessing::class, function (JobProcessing $event): void {
            /** @var array<string, mixed> $payload */
            $payload = $event->job->payload();

            $this->start($payload, $event->job->resolveName());
        });

        $events->listen(JobProcessed::class, function (JobProcessed $event): void {
            $this->finish();
        });

        $events->listen(JobFailed::class, function (JobFailed $event): void {
            $this->finish($event->exception->getMessage());
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function start(array $payload, string $name): void
    {
        $this->span = $this->tracing->tracer()
            ->spanBuilder($name === '' ? 'queue.job' : $name)
            ->setParent($this->tracing->extract($this->carrierFrom($payload)))
            ->setSpanKind(SpanKind::KIND_CONSUMER)
            ->setAttribute('messaging.operation.name', 'process')
            ->startSpan();

        $this->scope = $this->span->activate();
    }

    public function finish(?string $error = null): void
    {
        if ($error !== null) {
            $this->span?->setStatus(StatusCode::STATUS_ERROR, $error);
        }

        $this->scope?->detach();
        $this->span?->end();

        $this->scope = null;
        $this->span = null;
    }

    public function currentSpan(): ?SpanInterface
    {
        return $this->span;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function carrierFrom(array $payload): array
    {
        $carrier = $payload[self::PAYLOAD_KEY] ?? null;

        if (! is_array($carrier)) {
            return [];
        }

        $headers = [];

        foreach ($carrier as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }
}
