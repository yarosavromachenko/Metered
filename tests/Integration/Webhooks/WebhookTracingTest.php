<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Outbox\OutboxMessage;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Infrastructure\Inbox\IntegrationEventDispatcher;
use Metered\Shared\Infrastructure\Tracing\Tracing;
use Metered\Tenancy\Domain\Role;
use Metered\Webhooks\Application\Command\RegisterEndpoint;
use Metered\Webhooks\Application\Command\RegisterEndpointHandler;
use Metered\Webhooks\Application\Delivery\WebhookTransport;
use Metered\Webhooks\Domain\Endpoint\EndpointUrl;
use Metered\Webhooks\Infrastructure\Http\GuardedTransport;
use Metered\Webhooks\Infrastructure\Queue\AttemptDeliveryJob;
use OpenTelemetry\API\Trace\SpanKind;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\Support\InMemoryTracing;
use Tests\Support\ScriptedResolver;
use Tests\Support\TenantFactory;

/**
 * The last hop of ADR-0012. A delivery is attempted by a scheduled pass, long
 * after the job that created it has finished, so its trace context is stored
 * with it; the pass queues the attempt inside that context, and the request
 * carries it to the receiver. The queue is `sync`, so the attempt runs inside
 * the pass and its spans can be asserted on directly.
 */

/**
 * A guarded transport over a mocked network that answers $status and keeps
 * every request it was asked to send.
 *
 * @param  ArrayObject<int, array{request: RequestInterface, response: ResponseInterface|null, error: mixed, options: array<array-key, mixed>}>  $sent
 */
function tracedTransport(Tracing $tracing, ArrayObject $sent, int $status = 204): GuardedTransport
{
    $stack = HandlerStack::create(new MockHandler([new Response($status)]));
    $stack->push(Middleware::history($sent));

    return new GuardedTransport(
        new ScriptedResolver([['93.184.216.34']]),
        new Client(['handler' => $stack]),
        tracing: $tracing,
    );
}

function tracedWebhookTenant(): TenantContext
{
    $project = TenantFactory::tenant('traced-webhooks');

    app(RegisterEndpointHandler::class)->handle(new RegisterEndpoint(
        $project->tenant(),
        'https://hooks.example.com/metered',
        'Billing sync',
        ['invoice.paid'],
        TenantFactory::member($project->organizationId, Role::Admin, 'ops@traced-webhooks.test'),
    ));

    return $project->tenant();
}

function tracedInvoicePaid(TenantContext $tenant): OutboxMessage
{
    return new OutboxMessage(
        Uuid::fromString('01924b7c-0000-7000-8000-00000000e001'),
        'invoice',
        Uuid::fromString('01924b7c-0000-7000-8000-00000000e0aa'),
        'invoice.paid',
        ['invoice_id' => '01924b7c-0000-7000-8000-00000000e0aa', 'organization_id' => $tenant->organizationId->value, 'project_id' => $tenant->projectId->value, 'number' => 'INV-000042', 'total_minor' => 4150],
        [],
        new DateTimeImmutable('now'),
    );
}

it('sends the receiver the trace of the event the delivery was made for', function (): void {
    $recorder = InMemoryTracing::install();
    /** @var ArrayObject<int, array{request: RequestInterface, response: ResponseInterface|null, error: mixed, options: array<array-key, mixed>}> $sent */
    $sent = new ArrayObject();
    app()->instance(WebhookTransport::class, tracedTransport($recorder->tracing, $sent));
    $tenant = tracedWebhookTenant();

    // The fan-out, inside the job that delivered the event to it.
    $event = $recorder->tracing->tracer()->spanBuilder('DeliverIntegrationEvent')->startSpan();
    $scope = $event->activate();
    app(IntegrationEventDispatcher::class)->dispatch(tracedInvoicePaid($tenant));
    $scope->detach();
    $event->end();

    $stored = DB::table('webhook_deliveries')->where('project_id', $tenant->projectId->value)->value('trace_context');

    expect($stored)->toBeString()
        ->and(is_string($stored) ? $stored : '')->toContain($event->getContext()->getTraceId());

    // The scheduled pass: a process of its own, with no context of its own.
    Artisan::call('webhooks:dispatch');

    $attempt = $recorder->named(AttemptDeliveryJob::class);
    $request = $recorder->named('POST');
    $traceId = $event->getContext()->getTraceId();

    expect($sent)->toHaveCount(1)
        ->and($attempt)->not->toBeNull()
        ->and($request)->not->toBeNull()
        ->and($attempt?->getContext()->getTraceId())->toBe($traceId)
        ->and($attempt?->getParentContext()->getSpanId())->toBe($event->getContext()->getSpanId())
        ->and($request?->getKind())->toBe(SpanKind::KIND_CLIENT)
        ->and($request?->getParentContext()->getSpanId())->toBe($attempt?->getContext()->getSpanId())
        ->and($request?->getAttributes()->get('server.address'))->toBe('hooks.example.com')
        ->and($request?->getAttributes()->get('http.response.status_code'))->toBe(204);

    // What the receiver sees: our client span as the parent of whatever it does.
    $header = $sent->getArrayCopy()[0]['request']->getHeaderLine('traceparent');

    expect($header)->toContain($traceId)
        ->and($header)->toContain((string) $request?->getContext()->getSpanId());
});

it('stores no context for a delivery made with tracing off, and still delivers it', function (): void {
    /** @var ArrayObject<int, array{request: RequestInterface, response: ResponseInterface|null, error: mixed, options: array<array-key, mixed>}> $sent */
    $sent = new ArrayObject();
    app()->instance(WebhookTransport::class, tracedTransport(Tracing::disabled(), $sent));
    $tenant = tracedWebhookTenant();

    app(IntegrationEventDispatcher::class)->dispatch(tracedInvoicePaid($tenant));
    Artisan::call('webhooks:dispatch');

    expect(DB::table('webhook_deliveries')->where('project_id', $tenant->projectId->value)->value('trace_context'))->toBeNull()
        ->and($sent)->toHaveCount(1)
        ->and($sent->getArrayCopy()[0]['request']->hasHeader('traceparent'))->toBeFalse();
});

it('marks a request the receiver rejected as a failed call', function (): void {
    $recorder = new InMemoryTracing();
    /** @var ArrayObject<int, array{request: RequestInterface, response: ResponseInterface|null, error: mixed, options: array<array-key, mixed>}> $sent */
    $sent = new ArrayObject();

    tracedTransport($recorder->tracing, $sent, 503)->send(EndpointUrl::fromString('https://hooks.example.com/metered'), [], '{}');

    $request = $recorder->named('POST');

    expect($request?->getStatus()->getCode())->toBe('Error')
        ->and($request?->getAttributes()->get('http.response.status_code'))->toBe(503);
});

it('opens no span for a destination it refuses to call', function (): void {
    $recorder = new InMemoryTracing();
    $transport = new GuardedTransport(new ScriptedResolver([['169.254.169.254']]), tracing: $recorder->tracing);

    $transport->send(EndpointUrl::fromString('https://hooks.example.com/metered'), [], '{}');

    expect($recorder->finished())->toBe([]);
});
