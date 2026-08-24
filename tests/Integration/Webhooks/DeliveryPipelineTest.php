<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Outbox\OutboxMessage;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Infrastructure\Inbox\IntegrationEventDispatcher;
use Metered\Tenancy\Domain\Role;
use Metered\Webhooks\Application\Command\RegisteredEndpoint;
use Metered\Webhooks\Application\Command\RegisterEndpoint;
use Metered\Webhooks\Application\Command\RegisterEndpointHandler;
use Metered\Webhooks\Application\Command\ReplayDelivery;
use Metered\Webhooks\Application\Command\ReplayDeliveryHandler;
use Metered\Webhooks\Application\Delivery\AttemptDelivery;
use Metered\Webhooks\Application\Delivery\AttemptDeliveryHandler;
use Metered\Webhooks\Application\Delivery\Jitter;
use Metered\Webhooks\Application\Delivery\WebhookTransport;
use Metered\Webhooks\Domain\Delivery\AttemptResult;
use Metered\Webhooks\Domain\Delivery\DeliveryRepository;
use Metered\Webhooks\Domain\Delivery\DeliveryStatus;
use Metered\Webhooks\Domain\Endpoint\BreakerState;
use Metered\Webhooks\Domain\Endpoint\EndpointRepository;
use Metered\Webhooks\Domain\Endpoint\EndpointUrl;
use Metered\Webhooks\Infrastructure\Queue\AttemptDeliveryJob;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Tests\Support\RecordingTransport;
use Tests\Support\TenantFactory;

/**
 * A project with one endpoint listening to invoice.paid, a receiver answering
 * from $results, no jitter, and the clock at 12:00.
 *
 * @param list<AttemptResult> $results
 *
 * @return array{tenant: TenantContext, endpoint: RegisteredEndpoint, transport: RecordingTransport, clock: MockClock}
 */
function webhookProject(array $results = [], string $slug = 'acme'): array
{
    $clock = new MockClock('2026-09-25 12:00:00', 'UTC');
    app()->instance(ClockInterface::class, $clock);
    $transport = new RecordingTransport($results === [] ? [AttemptResult::responded(200, 5, 'ok')] : $results);
    app()->instance(WebhookTransport::class, $transport);
    app()->instance(Jitter::class, new class implements Jitter {
        public function draw(): int
        {
            return 0;
        }
    });

    $project = TenantFactory::tenant($slug);
    $endpoint = app(RegisterEndpointHandler::class)->handle(new RegisterEndpoint(
        $project->tenant(),
        'https://hooks.example.com/metered',
        'Billing sync',
        ['invoice.paid'],
        TenantFactory::member($project->organizationId, Role::Admin, 'ops@' . $slug . '.test'),
    ));

    return ['tenant' => $project->tenant(), 'endpoint' => $endpoint, 'transport' => $transport, 'clock' => $clock];
}

function invoicePaidEvent(TenantContext $tenant, string $id = '01924b7c-0000-7000-8000-00000000d001', string $type = 'invoice.paid'): OutboxMessage
{
    return new OutboxMessage(
        Uuid::fromString($id),
        'invoice',
        Uuid::fromString('01924b7c-0000-7000-8000-00000000d0aa'),
        $type,
        ['invoice_id' => '01924b7c-0000-7000-8000-00000000d0aa', 'organization_id' => $tenant->organizationId->value, 'project_id' => $tenant->projectId->value, 'number' => 'INV-000042', 'total_minor' => 4150],
        [],
        new DateTimeImmutable('2026-09-25T11:59:00Z'),
    );
}

/**
 * The delivery the fan-out made for the one event in the test.
 */
function onlyDelivery(TenantContext $tenant): Uuid
{
    $id = DB::table('webhook_deliveries')->where('project_id', $tenant->projectId->value)->value('id');

    return Uuid::fromString(is_string($id) ? $id : throw new LogicException('No delivery was made.'));
}

function attempt(TenantContext $tenant, Uuid $delivery): ?AttemptResult
{
    return app(AttemptDeliveryHandler::class)->handle(new AttemptDelivery($tenant, $delivery));
}

it('turns an event into one signed delivery for each endpoint that listens, once', function (): void {
    // The other project first: each call binds its own receiver, and the
    // one under test must be bound last.
    webhookProject([], 'north-wind');
    ['tenant' => $tenant, 'endpoint' => $registered, 'transport' => $transport] = webhookProject();
    $dispatcher = app(IntegrationEventDispatcher::class);

    $dispatcher->dispatch(invoicePaidEvent($tenant));
    $dispatcher->dispatch(invoicePaidEvent($tenant));
    $dispatcher->dispatch(invoicePaidEvent($tenant, '01924b7c-0000-7000-8000-00000000d002', 'invoice.finalized'));

    $delivery = onlyDelivery($tenant);
    attempt($tenant, $delivery);

    $sent = $transport->sent[0];
    $body = json_decode($sent['body'], true);
    preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $sent['headers']['X-Metered-Signature'], $signature);
    $timestamp = $signature[1] ?? '';

    expect(DB::table('webhook_deliveries')->count())->toBe(1)
        ->and($transport->sent)->toHaveCount(1)
        ->and($sent['url'])->toBe('https://hooks.example.com/metered')
        ->and($body)->toBe([
            'id' => '01924b7c-0000-7000-8000-00000000d001',
            'type' => 'invoice.paid',
            'created_at' => '2026-09-25T11:59:00+00:00',
            'data' => ['invoice_id' => '01924b7c-0000-7000-8000-00000000d0aa', 'number' => 'INV-000042', 'total_minor' => 4150],
        ])
        ->and($sent['headers']['X-Metered-Event-Id'])->toBe('01924b7c-0000-7000-8000-00000000d001')
        ->and($sent['headers']['X-Metered-Event-Type'])->toBe('invoice.paid')
        ->and($timestamp)->toBe((string) new DateTimeImmutable('2026-09-25T12:00:00Z')->getTimestamp())
        ->and($signature[2] ?? null)->toBe(hash_hmac('sha256', $timestamp . '.' . $sent['body'], $registered->secret->reveal()))
        ->and(app(DeliveryRepository::class)->find($tenant, $delivery)?->status)->toBe(DeliveryStatus::Succeeded);
});

it('attempts only what is due, and records every attempt', function (): void {
    ['tenant' => $tenant, 'transport' => $transport, 'clock' => $clock] = webhookProject([
        AttemptResult::responded(503, 40, 'maintenance'),
        AttemptResult::responded(200, 12, 'ok'),
    ]);
    app(IntegrationEventDispatcher::class)->dispatch(invoicePaidEvent($tenant));
    $delivery = onlyDelivery($tenant);

    attempt($tenant, $delivery);
    $early = attempt($tenant, $delivery);
    $clock->modify('+60 seconds');
    attempt($tenant, $delivery);

    $log = DB::table('webhook_attempts')->where('delivery_id', $delivery->value)->orderBy('id')->get(['number', 'status_code', 'duration_ms', 'response_excerpt'])->map(static fn(object $r): array => (array) $r)->all();

    expect($early)->toBeNull()
        ->and($transport->sent)->toHaveCount(2)
        ->and($log)->toBe([
            ['number' => 1, 'status_code' => 503, 'duration_ms' => 40, 'response_excerpt' => 'maintenance'],
            ['number' => 2, 'status_code' => 200, 'duration_ms' => 12, 'response_excerpt' => 'ok'],
        ]);
});

it('dead-letters a delivery after ten failures, and replays it from the start', function (): void {
    ['tenant' => $tenant, 'endpoint' => $registered, 'transport' => $transport, 'clock' => $clock] = webhookProject([
        AttemptResult::unreachable('connection refused', 3),
    ]);
    app(IntegrationEventDispatcher::class)->dispatch(invoicePaidEvent($tenant));
    $delivery = onlyDelivery($tenant);
    $deliveries = app(DeliveryRepository::class);
    $actor = TenantFactory::member($tenant->organizationId, Role::Admin, 'replay@acme.test');

    // A day between attempts: longer than any wait, and than the breaker's
    // cooldown, so each attempt is due and the half-open probe goes out.
    foreach (range(1, 10) as $ignored) {
        attempt($tenant, $delivery);
        $clock->modify('+1 day');
    }

    $dead = $deliveries->find($tenant, $delivery);
    $afterDeath = attempt($tenant, $delivery);

    app(ReplayDeliveryHandler::class)->handle(new ReplayDelivery($tenant, $delivery, $actor));
    app()->instance(WebhookTransport::class, $recovered = new RecordingTransport([AttemptResult::responded(200, 5, '')]));
    attempt($tenant, $delivery);

    expect($transport->sent)->toHaveCount(10)
        ->and($dead?->status)->toBe(DeliveryStatus::Dead)
        ->and($dead?->attempts)->toBe(10)
        ->and($afterDeath)->toBeNull()
        ->and($recovered->sent[0]['body'])->toBe($transport->sent[0]['body'])
        ->and($deliveries->find($tenant, $delivery)?->status)->toBe(DeliveryStatus::Succeeded)
        ->and($deliveries->find($tenant, $delivery)?->attempts)->toBe(1)
        ->and(DB::table('webhook_attempts')->where('delivery_id', $delivery->value)->count())->toBe(11)
        ->and(app(EndpointRepository::class)->find($tenant, $registered->endpoint->id)?->breaker->state)->toBe(BreakerState::Closed)
        ->and(DB::table('audit_log')->where('subject_id', $delivery->value)->value('action'))->toBe('webhook_delivery.replayed');
});

it('opens the breaker after five failures in a row, holds deliveries back, then lets one probe through', function (): void {
    ['tenant' => $tenant, 'endpoint' => $registered, 'transport' => $transport, 'clock' => $clock] = webhookProject([
        AttemptResult::responded(500, 5, ''),
        AttemptResult::responded(500, 5, ''),
        AttemptResult::responded(500, 5, ''),
        AttemptResult::responded(500, 5, ''),
        AttemptResult::responded(500, 5, ''),
        AttemptResult::responded(200, 5, ''),
    ]);
    $dispatcher = app(IntegrationEventDispatcher::class);

    foreach (range(1, 6) as $n) {
        $dispatcher->dispatch(invoicePaidEvent($tenant, sprintf('01924b7c-0000-7000-8000-00000000d1%02d', $n)));
    }

    $ids = array_map(
        static fn(mixed $id): Uuid => Uuid::fromString(is_string($id) ? $id : ''),
        DB::table('webhook_deliveries')->orderBy('event_id')->pluck('id')->all(),
    );

    foreach (array_slice($ids, 0, 5) as $id) {
        attempt($tenant, $id);
    }

    $endpoints = app(EndpointRepository::class);
    $open = $endpoints->find($tenant, $registered->endpoint->id)?->breaker;
    $heldBack = attempt($tenant, $ids[5]);
    $postponedTo = app(DeliveryRepository::class)->find($tenant, $ids[5])?->nextAttemptAt;

    $clock->modify('+5 minutes');
    attempt($tenant, $ids[5]);

    expect($open?->state)->toBe(BreakerState::Open)
        ->and($open?->consecutiveFailures)->toBe(5)
        ->and($heldBack)->toBeNull()
        ->and($transport->sent)->toHaveCount(6)
        ->and($postponedTo?->format(DATE_ATOM))->toBe('2026-09-25T12:05:00+00:00')
        ->and(app(DeliveryRepository::class)->find($tenant, $ids[5])?->attempts)->toBe(1)
        ->and($endpoints->find($tenant, $registered->endpoint->id)?->breaker->state)->toBe(BreakerState::Closed);
});

it('keeps a disabled endpoint’s deliveries waiting instead of sending them', function (): void {
    ['tenant' => $tenant, 'endpoint' => $registered, 'transport' => $transport] = webhookProject();
    app(IntegrationEventDispatcher::class)->dispatch(invoicePaidEvent($tenant));
    $endpoints = app(EndpointRepository::class);
    $endpoint = $registered->endpoint;
    $endpoints->save($endpoint->reconfigure($endpoint->url, $endpoint->description, $endpoint->eventTypes, false));

    attempt($tenant, onlyDelivery($tenant));

    expect($transport->sent)->toBe([])
        ->and(app(DeliveryRepository::class)->find($tenant, onlyDelivery($tenant))?->status)->toBe(DeliveryStatus::Pending);
});

it('leases a delivery while its request is out, so a second worker finds it not due', function (): void {
    ['tenant' => $tenant, 'clock' => $clock] = webhookProject();
    app(IntegrationEventDispatcher::class)->dispatch(invoicePaidEvent($tenant));
    $delivery = onlyDelivery($tenant);
    $deliveries = app(DeliveryRepository::class);

    // What the first worker sees while its request is still out: the lease.
    app()->instance(WebhookTransport::class, new class ($deliveries, $tenant, $delivery) implements WebhookTransport {
        public ?DateTimeImmutable $leasedUntil = null;

        public function __construct(private readonly DeliveryRepository $deliveries, private readonly TenantContext $tenant, private readonly Uuid $delivery) {}

        public function send(EndpointUrl $url, array $headers, string $body): AttemptResult
        {
            $this->leasedUntil = $this->deliveries->find($this->tenant, $this->delivery)?->nextAttemptAt;

            return AttemptResult::responded(200, 1, '');
        }
    });

    attempt($tenant, $delivery);
    $transport = app(WebhookTransport::class);

    $leasedUntil = $transport instanceof WebhookTransport && property_exists($transport, 'leasedUntil') ? $transport->leasedUntil : null;

    expect($leasedUntil instanceof DateTimeImmutable ? $leasedUntil->format(DATE_ATOM) : null)
        ->toBe($clock->now()->modify('+60 seconds')->format(DATE_ATOM));
});

it('makes no delivery for an event nobody listens to, or for another project', function (): void {
    ['tenant' => $tenant] = webhookProject();
    $other = TenantFactory::tenant('north-wind')->tenant();

    app(IntegrationEventDispatcher::class)->dispatch(invoicePaidEvent($tenant, '01924b7c-0000-7000-8000-00000000d003', 'subscription.created'));
    app(IntegrationEventDispatcher::class)->dispatch(invoicePaidEvent($other, '01924b7c-0000-7000-8000-00000000d004'));

    expect(DB::table('webhook_deliveries')->count())->toBe(0);
});

it('queues an attempt for every due delivery and nothing else', function (): void {
    Queue::fake();
    $project = TenantFactory::tenant();
    $actor = TenantFactory::member($project->organizationId, Role::Admin);
    $endpoint = app(RegisterEndpointHandler::class)->handle(new RegisterEndpoint($project->tenant(), 'https://hooks.example.com', '', ['invoice.paid'], $actor))->endpoint;
    $insert = static fn(string $id, string $status, ?string $next): bool => DB::table('webhook_deliveries')->insert([
        'id' => $id, 'organization_id' => $project->organizationId->value, 'project_id' => $project->id->value,
        'endpoint_id' => $endpoint->id->value, 'event_id' => (string) Uuid::fromString($id), 'event_type' => 'invoice.paid',
        'body' => '{}', 'status' => $status, 'attempts' => 0, 'next_attempt_at' => $next, 'created_at' => '2026-09-25 00:00:00+00',
    ]);
    $insert('01924b7c-0000-7000-8000-00000000e001', 'pending', '2020-01-01 00:00:00+00');
    $insert('01924b7c-0000-7000-8000-00000000e002', 'pending', '2999-01-01 00:00:00+00');
    $insert('01924b7c-0000-7000-8000-00000000e003', 'dead', null);

    expect(Artisan::call('webhooks:dispatch'))->toBe(0)
        ->and(Artisan::output())->toContain('Queued 1 delivery attempt(s).');

    Queue::assertPushedOn('webhooks', AttemptDeliveryJob::class, static fn(AttemptDeliveryJob $job): bool => $job->deliveryId === '01924b7c-0000-7000-8000-00000000e001'
        && $job->uniqueId() === '01924b7c-0000-7000-8000-00000000e001');
    Queue::assertPushed(AttemptDeliveryJob::class, 1);
});
