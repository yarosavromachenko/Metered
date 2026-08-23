<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Metered\Shared\Domain\Access\PermissionDenied;
use Metered\Tenancy\Domain\Role;
use Metered\Webhooks\Application\Command\ReconfigureEndpoint;
use Metered\Webhooks\Application\Command\ReconfigureEndpointHandler;
use Metered\Webhooks\Application\Command\RegisterEndpoint;
use Metered\Webhooks\Application\Command\RegisterEndpointHandler;
use Metered\Webhooks\Application\Command\RemoveEndpoint;
use Metered\Webhooks\Application\Command\RemoveEndpointHandler;
use Metered\Webhooks\Application\Command\RotateEndpointSecret;
use Metered\Webhooks\Application\Command\RotateEndpointSecretHandler;
use Metered\Webhooks\Application\Command\WebhookNotFound;
use Metered\Webhooks\Domain\Endpoint\EndpointRepository;
use Metered\Webhooks\Domain\Endpoint\EventType;
use Metered\Webhooks\Domain\Exception\InvalidEndpoint;
use Metered\Webhooks\Domain\Signing\SecretKey;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Tests\Support\TenantFactory;

it('registers an endpoint, shows its secret once and stores it only encrypted', function (): void {
    $project = TenantFactory::tenant();
    $actor = TenantFactory::member($project->organizationId, Role::Admin);

    $registered = app(RegisterEndpointHandler::class)->handle(new RegisterEndpoint(
        $project->tenant(),
        'https://hooks.example.com/metered',
        'Billing sync',
        ['invoice.paid', 'SUBSCRIPTION.CREATED'],
        $actor,
    ));

    $row = DB::table('webhook_endpoints')->where('id', $registered->endpoint->id->value)->first();
    $payload = DB::table('audit_log')->where('subject_id', $registered->endpoint->id->value)->value('payload');
    $audit = is_string($payload) ? $payload : '';
    $stored = app(EndpointRepository::class)->find($project->tenant(), $registered->endpoint->id);

    expect($registered->secret->reveal())->toStartWith('whsec_')
        ->and(strlen($registered->secret->reveal()))->toBe(49)
        ->and(json_encode($row))->not->toContain($registered->secret->reveal())
        ->and($audit)->not->toContain($registered->secret->reveal())
        ->and($audit)->toContain($registered->secret->masked())
        ->and($stored?->secret->equals($registered->secret))->toBeTrue()
        ->and($stored?->eventTypes)->toBe([EventType::SubscriptionCreated, EventType::InvoicePaid]);
});

it('refuses an event that does not exist, and a URL that is not https', function (string $url, array $events, string $message): void {
    /** @var list<string> $events */
    $project = TenantFactory::tenant();

    expect(static fn() => app(RegisterEndpointHandler::class)->handle(new RegisterEndpoint(
        $project->tenant(),
        $url,
        '',
        $events,
        TenantFactory::member($project->organizationId, Role::Admin),
    )))->toThrow(InvalidEndpoint::class, $message);
})->with([
    ['https://hooks.example.com', ['invoice.pay'], '"invoice.pay" is not an event'],
    ['http://hooks.example.com', ['invoice.paid'], 'over https only'],
]);

it('rotates the secret, keeping the old one signing for a day', function (): void {
    app()->instance(ClockInterface::class, new MockClock('2026-09-25 12:00:00', 'UTC'));
    $project = TenantFactory::tenant();
    $actor = TenantFactory::member($project->organizationId, Role::Admin);
    $registered = app(RegisterEndpointHandler::class)->handle(new RegisterEndpoint($project->tenant(), 'https://hooks.example.com', '', ['invoice.paid'], $actor));

    $rotated = app(RotateEndpointSecretHandler::class)->handle(new RotateEndpointSecret($project->tenant(), $registered->endpoint->id, $actor));
    $stored = app(EndpointRepository::class)->find($project->tenant(), $registered->endpoint->id);

    expect($rotated->secret->equals($registered->secret))->toBeFalse()
        ->and(array_map(static fn(SecretKey $s): string => $s->reveal(), $stored?->signingSecrets(new DateTimeImmutable('2026-09-26T11:59:00Z')) ?? []))
        ->toBe([$rotated->secret->reveal(), $registered->secret->reveal()])
        ->and($stored?->signingSecrets(new DateTimeImmutable('2026-09-26T12:00:00Z')))->toHaveCount(1);
});

it('reconfigures and removes an endpoint, taking its deliveries with it', function (): void {
    $project = TenantFactory::tenant();
    $actor = TenantFactory::member($project->organizationId, Role::Admin);
    $id = app(RegisterEndpointHandler::class)->handle(new RegisterEndpoint($project->tenant(), 'https://hooks.example.com', '', ['invoice.paid'], $actor))->endpoint->id;

    $changed = app(ReconfigureEndpointHandler::class)->handle(new ReconfigureEndpoint($project->tenant(), $id, 'https://new.example.com/in', 'Moved', ['invoice.voided'], false, $actor));
    app(RemoveEndpointHandler::class)->handle(new RemoveEndpoint($project->tenant(), $id, $actor));

    expect($changed->url->value)->toBe('https://new.example.com/in')
        ->and($changed->enabled)->toBeFalse()
        ->and($changed->eventTypes)->toBe([EventType::InvoiceVoided])
        ->and(app(EndpointRepository::class)->find($project->tenant(), $id))->toBeNull()
        ->and(DB::table('audit_log')->where('subject_id', $id->value)->orderBy('id')->pluck('action')->all())
        ->toBe(['webhook_endpoint.registered', 'webhook_endpoint.reconfigured', 'webhook_endpoint.removed']);
});

it('lets only those who operate webhooks manage them', function (Role $role): void {
    $project = TenantFactory::tenant();

    app(RegisterEndpointHandler::class)->handle(new RegisterEndpoint(
        $project->tenant(),
        'https://hooks.example.com',
        '',
        ['invoice.paid'],
        TenantFactory::member($project->organizationId, $role),
    ));
})->with([Role::Viewer, Role::BillingOperator])->throws(PermissionDenied::class);

it('finds no endpoint of another project', function (): void {
    $project = TenantFactory::tenant();
    $actor = TenantFactory::member($project->organizationId, Role::Admin);
    $id = app(RegisterEndpointHandler::class)->handle(new RegisterEndpoint($project->tenant(), 'https://hooks.example.com', '', ['invoice.paid'], $actor))->endpoint->id;
    $rival = TenantFactory::tenant('north-wind');

    app(RotateEndpointSecretHandler::class)->handle(new RotateEndpointSecret($rival->tenant(), $id, TenantFactory::member($rival->organizationId)));
})->throws(WebhookNotFound::class);
