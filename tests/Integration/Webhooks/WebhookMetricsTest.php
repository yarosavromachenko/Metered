<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Metered\Shared\Application\Metrics\GaugeReading;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Outbox\OutboxMessage;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Infrastructure\Inbox\IntegrationEventDispatcher;
use Metered\Tenancy\Domain\Role;
use Metered\Webhooks\Application\Command\RegisteredEndpoint;
use Metered\Webhooks\Application\Command\RegisterEndpoint;
use Metered\Webhooks\Application\Command\RegisterEndpointHandler;
use Metered\Webhooks\Application\Delivery\AttemptDelivery;
use Metered\Webhooks\Application\Delivery\AttemptDeliveryHandler;
use Metered\Webhooks\Application\Delivery\WebhookTransport;
use Metered\Webhooks\Domain\Delivery\AttemptResult;
use Metered\Webhooks\Infrastructure\Persistence\BreakerGauges;
use Tests\Support\InMemoryMetrics;
use Tests\Support\RecordingTransport;
use Tests\Support\TenantFactory;

/**
 * The delivery path's instruments: every attempt counted by outcome and
 * endpoint, and each enabled endpoint's breaker read as a gauge.
 *
 * @return array{tenant: TenantContext, endpoint: RegisteredEndpoint}
 */
function meteredWebhookTenant(AttemptResult $answer): array
{
    app()->instance(WebhookTransport::class, new RecordingTransport([$answer]));
    $project = TenantFactory::tenant('metered-webhooks');

    $endpoint = app(RegisterEndpointHandler::class)->handle(new RegisterEndpoint(
        $project->tenant(),
        'https://hooks.example.com/metered',
        'Billing sync',
        ['invoice.paid'],
        TenantFactory::member($project->organizationId, Role::Admin, 'ops@metered-webhooks.test'),
    ));

    app(IntegrationEventDispatcher::class)->dispatch(new OutboxMessage(
        Uuid::fromString('01924b7c-0000-7000-8000-00000000f001'),
        'invoice',
        Uuid::fromString('01924b7c-0000-7000-8000-00000000f0aa'),
        'invoice.paid',
        ['organization_id' => $project->tenant()->organizationId->value, 'project_id' => $project->tenant()->projectId->value],
        [],
        new DateTimeImmutable('now'),
    ));

    return ['tenant' => $project->tenant(), 'endpoint' => $endpoint];
}

function attemptMeteredDelivery(TenantContext $tenant): void
{
    $id = DB::table('webhook_deliveries')->where('project_id', $tenant->projectId->value)->value('id');

    app(AttemptDeliveryHandler::class)->handle(new AttemptDelivery($tenant, Uuid::fromString(is_string($id) ? $id : '')));
}

it('counts a delivered attempt by endpoint and times the answer', function (): void {
    $metrics = InMemoryMetrics::install();
    ['tenant' => $tenant, 'endpoint' => $registered] = meteredWebhookTenant(AttemptResult::responded(200, 120, 'ok'));

    attemptMeteredDelivery($tenant);

    $endpoint = $registered->endpoint->id->value;

    expect($metrics->counted('webhook.deliveries', ['endpoint' => $endpoint, 'outcome' => 'delivered']))->toBe(1)
        ->and($metrics->histogram('webhook.delivery.duration', ['endpoint' => $endpoint]))->toBe(['count' => 1, 'sum' => 0.12]);
});

it('counts an attempt the receiver failed as one to retry', function (): void {
    $metrics = InMemoryMetrics::install();
    ['tenant' => $tenant, 'endpoint' => $registered] = meteredWebhookTenant(AttemptResult::responded(503, 40, 'down'));

    attemptMeteredDelivery($tenant);

    expect($metrics->counted('webhook.deliveries', ['endpoint' => $registered->endpoint->id->value, 'outcome' => 'retry']))->toBe(1);
});

it('counts a refused destination without timing a call that was never made', function (): void {
    $metrics = InMemoryMetrics::install();
    ['tenant' => $tenant, 'endpoint' => $registered] = meteredWebhookTenant(AttemptResult::refused('resolves to 10.0.0.1'));

    attemptMeteredDelivery($tenant);

    expect($metrics->counted('webhook.deliveries', ['endpoint' => $registered->endpoint->id->value, 'outcome' => 'refused']))->toBe(1)
        ->and($metrics->histogram('webhook.delivery.duration', ['endpoint' => $registered->endpoint->id->value])['count'])->toBe(0);
});

it('reads each enabled endpoint\'s breaker as open or not', function (): void {
    ['endpoint' => $registered] = meteredWebhookTenant(AttemptResult::responded(200, 5, 'ok'));
    $endpoint = $registered->endpoint->id->value;

    $read = static fn(): array => array_map(
        static fn(GaugeReading $reading): array => [$reading->labels['endpoint'] ?? '', $reading->value],
        array_values(array_filter(app(BreakerGauges::class)->read(), static fn(GaugeReading $reading): bool => ($reading->labels['endpoint'] ?? '') === $endpoint)),
    );

    expect($read())->toBe([[$endpoint, 0]]);

    DB::table('webhook_endpoints')->where('id', $endpoint)->update(['breaker_state' => 'open', 'breaker_changed_at' => now()]);
    expect($read())->toBe([[$endpoint, 1]]);

    // Stopped by its owner: nothing is failing, and nothing is reported.
    DB::table('webhook_endpoints')->where('id', $endpoint)->update(['enabled' => false]);
    expect($read())->toBe([]);
});
