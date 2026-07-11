<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Metering\Aggregation;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Domain\Project;
use Metered\Tenancy\Domain\Role;
use Metered\Tenancy\Infrastructure\Eloquent\User;
use Metered\Tenancy\Presentation\Filament\PanelScope;
use Metered\Usage\Presentation\Filament\Resources\Events\Pages\ListUsageEvents;
use Metered\Usage\Presentation\Filament\Resources\Events\UsageEventResource;
use Metered\Usage\Presentation\Filament\Widgets\IngestionHealth;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

use Tests\Support\CatalogFactory;
use Tests\Support\TenantFactory;

/**
 * The usage screens, and the rule every one of them answers to: a screen
 * shows the project in the panel scope and nothing else.
 */
function signedInOn(string $organizationSlug = 'acme'): Project
{
    $project = TenantFactory::tenant($organizationSlug);
    $member = TenantFactory::member($project->organizationId, Role::Admin, 'admin@' . $organizationSlug . '.example');

    $user = User::query()->find($member->userId?->value);
    actingAs($user instanceof User ? $user : throw new RuntimeException('No user.'));

    app('request')->setLaravelSession(app('session.store'));
    app(PanelScope::class)->switchTo($project->id->value);

    return $project;
}

function storedEvent(
    TenantContext $tenant,
    string $eventId,
    string $meterId,
    string $customerId,
    string $meterCode = 'api.requests',
    string $customerRef = 'cus_4471',
): void {
    DB::table('usage_events')->insert([
        'id' => app(IdentifierGenerator::class)->generate()->value,
        'organization_id' => $tenant->organizationId->value,
        'project_id' => $tenant->projectId->value,
        'event_id' => $eventId,
        'customer_id' => $customerId,
        'meter_id' => $meterId,
        'meter_code' => $meterCode,
        'customer_ref' => $customerRef,
        'quantity' => '2.500000',
        'occurred_at' => now()->subMinutes(5),
        'received_at' => now()->subMinutes(5),
        'properties' => '{}',
    ]);
}

function storedRejection(TenantContext $tenant, string $eventId, string $reason = 'unknown_meter'): void
{
    DB::table('usage_event_rejections')->insert([
        'id' => app(IdentifierGenerator::class)->generate()->value,
        'organization_id' => $tenant->organizationId->value,
        'project_id' => $tenant->projectId->value,
        'event_id' => $eventId,
        'reason' => $reason,
        'detail' => 'No meter in this project answers to that code.',
        'payload' => '{"event_id":"' . $eventId . '"}',
        'rejected_at' => now()->subMinutes(2),
    ]);
}

function storedAggregate(
    TenantContext $tenant,
    string $meterId,
    string $customerId,
    string $quantity,
    string $meterCode = 'api.requests',
    string $customerRef = 'cus_4471',
): void {
    DB::table('usage_aggregates')->insert([
        'organization_id' => $tenant->organizationId->value,
        'project_id' => $tenant->projectId->value,
        'customer_id' => $customerId,
        'meter_id' => $meterId,
        'meter_code' => $meterCode,
        'customer_ref' => $customerRef,
        'bucket_start' => now()->startOfHour(),
        'quantity' => $quantity,
        'event_count' => 2,
        'updated_at' => now(),
    ]);
}

it('lists this project’s events and never another tenant’s', function (): void {
    $mine = signedInOn('acme');
    $theirs = TenantFactory::tenant('north-wind');

    $meter = CatalogFactory::meter($mine->tenant(), 'api.requests');
    $customer = CatalogFactory::customer($mine->tenant(), 'cus_mine');
    $theirMeter = CatalogFactory::meter($theirs->tenant(), 'api.requests');
    $theirCustomer = CatalogFactory::customer($theirs->tenant(), 'cus_theirs');

    storedEvent($mine->tenant(), 'evt_mine', $meter->id->value, $customer->id->value, 'api.requests', 'cus_mine');
    storedEvent($theirs->tenant(), 'evt_theirs', $theirMeter->id->value, $theirCustomer->id->value, 'api.requests', 'cus_theirs');

    get('/admin/events/usage-events')
        ->assertOk()
        ->assertSee('cus_mine')
        ->assertDontSee('cus_theirs');
});

it('offers every meter the project defined as a filter, sent under or not', function (): void {
    $mine = signedInOn('acme');
    $theirs = TenantFactory::tenant('north-wind');

    CatalogFactory::meter($mine->tenant(), 'api.requests');
    CatalogFactory::meter($mine->tenant(), 'storage.gb', Aggregation::Max);
    CatalogFactory::meter($theirs->tenant(), 'emails.sent');

    expect(UsageEventResource::meterCodes())->toBe([
        'api.requests' => 'api.requests',
        'storage.gb' => 'storage.gb',
    ]);
});

it('pages the explorer without counting every event the project has', function (): void {
    $project = signedInOn();
    $meter = CatalogFactory::meter($project->tenant(), 'api.requests');
    $customer = CatalogFactory::customer($project->tenant(), 'cus_4471');
    storedEvent($project->tenant(), 'evt_1', $meter->id->value, $customer->id->value);

    $counts = [];
    DB::listen(static function (QueryExecuted $query) use (&$counts): void {
        if (str_contains($query->sql, 'count(') && str_contains($query->sql, 'usage_events')) {
            $counts[] = $query->sql;
        }
    });

    Livewire::test(ListUsageEvents::class)->assertSee('cus_4471');

    expect($counts)->toBe([]);
});

it('shows rejections with the reason a tenant can act on', function (): void {
    $project = signedInOn();

    storedRejection($project->tenant(), 'evt_rejected');

    get('/admin/rejections')
        ->assertOk()
        ->assertSee('evt_rejected')
        ->assertSee('Unknown meter');
});

it('shows no rejections from another tenant', function (): void {
    $mine = signedInOn('acme');
    $theirs = TenantFactory::tenant('north-wind');

    storedRejection($mine->tenant(), 'evt_mine');
    storedRejection($theirs->tenant(), 'evt_theirs');

    get('/admin/rejections')->assertOk()->assertSee('evt_mine')->assertDontSee('evt_theirs');
});

it('lists aggregates with the fold that produced them', function (): void {
    $project = signedInOn();
    $meter = CatalogFactory::meter($project->tenant(), 'seats.peak', Aggregation::Max);
    $customer = CatalogFactory::customer($project->tenant(), 'cus_4471');

    storedAggregate($project->tenant(), $meter->id->value, $customer->id->value, '12.000000', 'seats.peak');

    get('/admin/aggregates')
        ->assertOk()
        ->assertSee('seats.peak')
        ->assertSee('12.000000');
});

it('keeps one tenant’s aggregates off another tenant’s screen', function (): void {
    $mine = signedInOn('acme');
    $theirs = TenantFactory::tenant('north-wind');

    $meter = CatalogFactory::meter($mine->tenant(), 'mine.requests');
    $customer = CatalogFactory::customer($mine->tenant(), 'cus_mine');
    $theirMeter = CatalogFactory::meter($theirs->tenant(), 'theirs.requests');
    $theirCustomer = CatalogFactory::customer($theirs->tenant(), 'cus_theirs');

    storedAggregate($mine->tenant(), $meter->id->value, $customer->id->value, '1.000000', 'mine.requests', 'cus_mine');
    storedAggregate($theirs->tenant(), $theirMeter->id->value, $theirCustomer->id->value, '9.000000', 'theirs.requests', 'cus_theirs');

    get('/admin/aggregates')->assertOk()->assertSee('mine.requests')->assertDontSee('theirs.requests');
});

it('reports ingestion health on the dashboard', function (): void {
    $project = signedInOn();
    $meter = CatalogFactory::meter($project->tenant(), 'api.requests');
    $customer = CatalogFactory::customer($project->tenant(), 'cus_4471');

    storedEvent($project->tenant(), 'evt_1', $meter->id->value, $customer->id->value);
    storedRejection($project->tenant(), 'evt_2');

    // The widget itself, not the page around it: a stats widget loads lazily,
    // so the dashboard's first response carries a placeholder rather than the
    // numbers.
    $widget = Livewire::test(IngestionHealth::class);

    $widget->assertSee('Waiting in the stream');
    $widget->assertSee('Events in the last hour');
    $widget->assertSee('Look at the rejections screen');
});

it('puts the widget on the dashboard where an operator will see it', function (): void {
    signedInOn();

    expect(Filament::getPanel('admin')->getWidgets())->toContain(IngestionHealth::class);

    get('/admin')->assertOk();
});
