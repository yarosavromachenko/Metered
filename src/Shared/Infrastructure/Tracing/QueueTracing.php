<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Tracing;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;

/**
 * Puts the trace context into the job payload and restores it in the worker.
 * It is a separate payload key, not a job property, so job classes stay
 * compatible across deployments.
 */
final class QueueTracing
{
    public const string PAYLOAD_KEY = 'metered_trace';

    private ?SpanInterface $span = null;

    private ?ScopeInterface $scope = null;

    public function __construct(private readonly Tracing $tracing) {}

    /**
     * Registers the queue hooks once per process; each event goes to the
     * instance $current returns at that moment.
     *
     * @param  Closure(): self  $current
     */
    public static function register(Dispatcher $events, Closure $current): void
    {
        Queue::createPayloadUsing(static fn(): array => [self::PAYLOAD_KEY => $current()->tracing->carrier()]);

        $events->listen(JobProcessing::class, static function (JobProcessing $event) use ($current): void {
            /** @var array<string, mixed> $payload */
            $payload = $event->job->payload();

            $current()->start($payload, $event->job->resolveName());
        });

        $events->listen(JobProcessed::class, static function (JobProcessed $event) use ($current): void {
            $current()->finish();
        });

        // A failed attempt that will be retried fires neither event above; its
        // scope must be closed here or the retry fails on the open scope.
        $events->listen(JobExceptionOccurred::class, static function (JobExceptionOccurred $event) use ($current): void {
            $current()->finish($event->exception->getMessage());
        });

        // Covers failures without an exception (max attempts, timeout).
        $events->listen(JobFailed::class, static function (JobFailed $event) use ($current): void {
            $current()->finish($event->exception->getMessage());
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
