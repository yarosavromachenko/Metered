<?php

declare(strict_types=1);

use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Domain\Project;
use Metered\Tenancy\Domain\Role;
use Metered\Webhooks\Application\Command\RegisterEndpoint;
use Metered\Webhooks\Application\Command\RegisterEndpointHandler;
use Metered\Webhooks\Domain\Endpoint\EndpointRepository;
use Metered\Webhooks\Infrastructure\Eloquent\WebhookDelivery;
use Metered\Webhooks\Infrastructure\Eloquent\WebhookEndpoint;
use Metered\Webhooks\Presentation\Filament\Actions\EditEndpointAction;
use Metered\Webhooks\Presentation\Filament\Actions\RegisterEndpointAction;
use Metered\Webhooks\Presentation\Filament\Actions\RemoveEndpointAction;
use Metered\Webhooks\Presentation\Filament\Actions\ReplayDeliveryAction;
use Metered\Webhooks\Presentation\Filament\Actions\RotateSecretAction;

use function Pest\Laravel\get;

use Tests\Support\PanelSession;
use Tests\Support\TenantFactory;

/**
 * An endpoint in $project, and a dead delivery to it with one attempt logged.
 *
 * @return array{endpoint: string, delivery: string}
 */
function panelWebhook(Project $project, string $url): array
{
    $endpoint = app(RegisterEndpointHandler::class)->handle(new RegisterEndpoint(
        $project->tenant(),
        $url,
        'Billing sync',
        ['invoice.paid'],
        TenantFactory::member($project->organizationId, Role::Owner, 'owner-' . substr($project->id->value, -6) . '@example.test'),
    ))->endpoint;
    $delivery = (string) Str::uuid7();

    DB::table('webhook_deliveries')->insert([
        'id' => $delivery, 'organization_id' => $project->organizationId->value, 'project_id' => $project->id->value,
        'endpoint_id' => $endpoint->id->value, 'event_id' => (string) Str::uuid7(), 'event_type' => 'invoice.paid',
        'body' => '{"id":"evt","type":"invoice.paid","data":{"number":"INV-000042"}}', 'status' => 'dead', 'attempts' => 10,
        'last_status_code' => 503, 'created_at' => '2026-09-25 12:00:00+00',
    ]);
    DB::table('webhook_attempts')->insert([
        'delivery_id' => $delivery, 'organization_id' => $project->organizationId->value, 'project_id' => $project->id->value,
        'number' => 10, 'attempted_at' => '2026-09-27 12:00:00+00', 'duration_ms' => 812, 'status_code' => 503, 'error' => null,
        'response_excerpt' => 'upstream unavailable',
    ]);

    return ['endpoint' => $endpoint->id->value, 'delivery' => $delivery];
}

it('lists this project’s endpoints and deliveries and no one else’s', function (): void {
    panelWebhook(TenantFactory::tenant('north-wind'), 'https://theirs.example.com/in');
    $project = PanelSession::signIn('acme', Role::Admin);
    ['delivery' => $delivery] = panelWebhook($project, 'https://ours.example.com/in');

    get('/admin/webhook-endpoints')->assertOk()->assertSee('https://ours.example.com/in')->assertSee('closed')->assertDontSee('theirs.example.com');
    get('/admin/webhook-deliveries')->assertOk()->assertSee('invoice.paid')->assertSee('Dead')->assertDontSee('theirs.example.com');
    get('/admin/webhook-deliveries/' . $delivery)->assertOk()
        ->assertSee('INV-000042')
        ->assertSee('upstream unavailable')
        ->assertSee('812');
});

it('does not open another project’s delivery', function (): void {
    ['delivery' => $theirs] = panelWebhook(TenantFactory::tenant('north-wind'), 'https://theirs.example.com/in');
    PanelSession::signIn('acme', Role::Admin);

    get('/admin/webhook-deliveries/' . $theirs)->assertNotFound();
});

it('registers, edits, rotates and removes an endpoint from the panel, showing each new secret once', function (): void {
    $project = PanelSession::signIn('acme', Role::Admin);

    RegisterEndpointAction::run(['url' => 'https://hooks.example.com/in', 'description' => 'Sync', 'events' => ['invoice.paid']]);
    $notifications = session('filament.notifications', []);
    $row = WebhookEndpoint::query()->firstOrFail();

    EditEndpointAction::run($row->id, ['url' => 'https://hooks.example.com/v2', 'description' => '', 'events' => ['invoice.voided'], 'enabled' => false]);
    RotateSecretAction::run($row->id);
    $stored = app(EndpointRepository::class)->find($project->tenant(), Uuid::fromString($row->id));
    RemoveEndpointAction::run($row->id);

    $bodies = array_map(static fn(mixed $n): string => is_array($n) && is_string($n['body'] ?? null) ? $n['body'] : '', is_array($notifications) ? $notifications : []);

    expect(array_values(array_filter($bodies, static fn(string $b): bool => str_starts_with($b, 'whsec_'))))->toHaveCount(1)
        ->and($stored?->url->value)->toBe('https://hooks.example.com/v2')
        ->and($stored?->enabled)->toBeFalse()
        ->and($stored?->previousSecret)->not->toBeNull()
        ->and(DB::table('webhook_endpoints')->count())->toBe(0);
});

it('replays a dead delivery from the panel', function (): void {
    $project = PanelSession::signIn('acme', Role::Admin);
    ['delivery' => $delivery] = panelWebhook($project, 'https://ours.example.com/in');

    ReplayDeliveryAction::run($delivery);

    expect(WebhookDelivery::query()->findOrFail($delivery)->status->value)->toBe('pending');
});

it('offers the webhook buttons only to those who operate webhooks', function (Role $role, bool $offered): void {
    $project = PanelSession::signIn('acme', $role);
    ['delivery' => $delivery] = panelWebhook($project, 'https://ours.example.com/in');
    $endpoint = WebhookEndpoint::query()->firstOrFail();
    $row = WebhookDelivery::query()->findOrFail($delivery);
    $visible = static fn(Action $action, ?Model $record = null): bool => ($record instanceof Model ? $action->record($record) : $action)->isVisible();

    expect($visible(RegisterEndpointAction::make()))->toBe($offered)
        ->and($visible(RotateSecretAction::make(), $endpoint))->toBe($offered)
        ->and($visible(RemoveEndpointAction::make(), $endpoint))->toBe($offered)
        ->and($visible(ReplayDeliveryAction::make(), $row))->toBe($offered);
})->with([
    'admin' => [Role::Admin, true],
    'owner' => [Role::Owner, true],
    'billing operator' => [Role::BillingOperator, false],
    'viewer' => [Role::Viewer, false],
]);
