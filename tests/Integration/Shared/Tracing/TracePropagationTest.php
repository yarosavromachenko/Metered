<?php

declare(strict_types=1);

use Illuminate\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Metered\Shared\Infrastructure\Tracing\QueueTracing;
use Metered\Shared\Infrastructure\Tracing\Tracing;
use OpenTelemetry\API\Trace\TracerProviderInterface;

use function Pest\Laravel\getJson;

use Tests\Support\InMemoryTracing;
use Tests\Support\TracedTestJob;

/**
 * The payload of the single job waiting on the database queue.
 *
 * @return array<string, mixed>
 */
function queuedPayload(): array
{
    $raw = DB::table('jobs')->value('payload');
    $decoded = json_decode(is_string($raw) ? $raw : '{}', true);

    $payload = [];

    foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
        $payload[(string) $key] = $value;
    }

    return $payload;
}

/**
 * Swaps the application's tracer for one that records in memory, and
 * re-registers the queue hook so it uses it.
 */
function inMemoryTracing(): InMemoryTracing
{
    $recorder = new InMemoryTracing();

    app()->instance(TracerProviderInterface::class, $recorder->tracing);
    app()->instance(Tracing::class, $recorder->tracing);

    new QueueTracing($recorder->tracing)->register(app('events'));

    return $recorder;
}

it('puts the current trace context into the job payload', function (): void {
    $recorder = inMemoryTracing();

    $request = $recorder->tracing->tracer()->spanBuilder('POST /api/v1/subscriptions')->startSpan();
    $scope = $request->activate();

    Queue::connection('database')->push(new TracedTestJob());

    $scope->detach();
    $request->end();

    $carrier = queuedPayload()[QueueTracing::PAYLOAD_KEY] ?? null;

    expect($carrier)->toBeArray();

    // The W3C header carries the trace id of the span that queued the job.
    expect(is_array($carrier) ? ($carrier['traceparent'] ?? '') : '')
        ->toContain($request->getContext()->getTraceId());
});

it('continues the request trace inside the job that request queued', function (): void {
    $recorder = inMemoryTracing();

    $request = $recorder->tracing->tracer()->spanBuilder('POST /api/v1/subscriptions')->startSpan();
    $scope = $request->activate();

    Queue::connection('database')->push(new TracedTestJob());

    $scope->detach();
    $request->end();

    $payload = queuedPayload();

    // The worker: a different process, minutes later, with no ambient context.
    $queue = new QueueTracing($recorder->tracing);
    $queue->start($payload, TracedTestJob::class);
    $queue->finish();

    $requestSpan = $recorder->named('POST /api/v1/subscriptions');
    $jobSpan = $recorder->named(TracedTestJob::class);

    expect($requestSpan)->not->toBeNull()
        ->and($jobSpan)->not->toBeNull()
        // One trace, two spans, the job hanging off the request that caused it.
        ->and($jobSpan?->getContext()->getTraceId())->toBe($requestSpan?->getContext()->getTraceId())
        ->and($jobSpan?->getParentContext()->getSpanId())->toBe($requestSpan?->getContext()->getSpanId());
});

it('starts a fresh trace when a job carries no context', function (): void {
    $recorder = inMemoryTracing();

    $queue = new QueueTracing($recorder->tracing);
    $queue->start([], TracedTestJob::class);
    $queue->finish();

    $span = $recorder->named(TracedTestJob::class);

    // A job queued before tracing existed still runs, and still gets a trace of
    // its own rather than an exception.
    expect($span)->not->toBeNull()
        ->and($span?->getParentContext()->isValid())->toBeFalse();
});

it('marks a failed job as an error', function (): void {
    $recorder = inMemoryTracing();

    $queue = new QueueTracing($recorder->tracing);
    $queue->start([], TracedTestJob::class);
    $queue->finish('the receiver refused the delivery');

    $span = $recorder->named(TracedTestJob::class);

    expect($span?->getStatus()->getCode())->toBe('Error')
        ->and($span?->getStatus()->getDescription())->toBe('the receiver refused the delivery');
});

it('closes an attempt that threw, so the retry starts cleanly', function (): void {
    // A dispatcher of its own, holding this one listener: the application's
    // listener would open scopes of its own and interleave with these.
    $recorder = new InMemoryTracing();
    $events = new Dispatcher(app());
    new QueueTracing($recorder->tracing)->register($events);
    $job = new SyncJob(app(), (string) json_encode(['displayName' => TracedTestJob::class, 'job' => 'x', 'data' => []]), 'redis', 'billing');

    $events->dispatch(new JobProcessing('redis', $job));
    $events->dispatch(new JobExceptionOccurred('redis', $job, new RuntimeException('first attempt failed')));
    $events->dispatch(new JobProcessing('redis', $job));
    $events->dispatch(new JobProcessed('redis', $job));

    $attempts = $recorder->allNamed(TracedTestJob::class);

    expect($attempts)->toHaveCount(2)
        ->and($attempts[0]->getStatus()->getCode())->toBe('Error')
        ->and($attempts[0]->getStatus()->getDescription())->toBe('first attempt failed')
        ->and($attempts[1]->getStatus()->getCode())->toBe('Unset');
});

it('joins a trace the caller already started', function (): void {
    $recorder = inMemoryTracing();

    // What a tenant sends when it instruments its own backend: our work then
    // appears inside their trace rather than starting a disconnected one.
    $incoming = ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'];

    $span = $recorder->tracing->tracer()
        ->spanBuilder('POST /api/v1/usage/events')
        ->setParent($recorder->tracing->extract($incoming))
        ->startSpan();
    $span->end();

    expect($recorder->named('POST /api/v1/usage/events')?->getContext()->getTraceId())
        ->toBe('4bf92f3577b34da6a3ce929d0e0e4736');
});

it('traces an API request through the middleware', function (): void {
    $recorder = inMemoryTracing();

    Route::middleware('api')->get('/api/v1/traced/{id}', fn(): JsonResponse => response()->json(['ok' => true]));

    getJson('/api/v1/traced/inv-117', ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'])
        ->assertOk();

    $span = $recorder->named('GET /api/v1/traced/{id}');

    expect($span)->not->toBeNull()
        // Named by route pattern, not by path: a million customer ids must not
        // become a million span names.
        ->and($span?->getContext()->getTraceId())->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($span?->getAttributes()->get('http.response.status_code'))->toBe(200)
        ->and($span?->getAttributes()->get('url.path'))->toBe('/api/v1/traced/inv-117');
});

it('marks a failing request as an error, but not a rejected one', function (): void {
    $recorder = inMemoryTracing();

    Route::middleware('api')->get('/api/v1/traced-missing', fn(): JsonResponse => response()->json([], 404));
    Route::middleware('api')->get('/api/v1/traced-broken', fn(): JsonResponse => response()->json([], 503));

    getJson('/api/v1/traced-missing');
    getJson('/api/v1/traced-broken');

    // A 404 is the client being told something; only a 5xx is this service
    // failing. Otherwise every rejected request would look like an outage.
    expect($recorder->named('GET /api/v1/traced-missing')?->getStatus()->getCode())->toBe('Unset')
        ->and($recorder->named('GET /api/v1/traced-broken')?->getStatus()->getCode())->toBe('Error');
});
