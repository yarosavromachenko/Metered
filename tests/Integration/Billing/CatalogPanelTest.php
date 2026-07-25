<?php

declare(strict_types=1);

use Metered\Billing\Domain\CustomerRepository;
use Metered\Billing\Domain\MeterRepository;
use Metered\Billing\Presentation\Filament\Actions\DefineMeterAction;
use Metered\Billing\Presentation\Filament\Actions\RegisterCustomerAction;
use Metered\Shared\Domain\Metering\Aggregation;
use Metered\Tenancy\Domain\Role;

use function Pest\Laravel\get;

use Tests\Support\CatalogFactory;
use Tests\Support\PanelSession;
use Tests\Support\TenantFactory;

/**
 * The catalog screens, and the one rule they all answer to: a screen shows the
 * project in the panel scope and nothing else.
 */
it('defines a meter from the panel, ready for the events that name it', function (): void {
    $project = PanelSession::signIn();

    DefineMeterAction::run([
        'code' => 'API.Requests',
        'name' => 'API requests',
        'aggregation' => 'count',
    ]);

    $meters = app(MeterRepository::class)->listFor($project->tenant());

    expect($meters)->toHaveCount(1)
        ->and($meters[0]->code->value)->toBe('api.requests')
        ->and($meters[0]->aggregation)->toBe(Aggregation::Count);
});

it('falls back to summing when the form sends an aggregation that is not one', function (): void {
    $project = PanelSession::signIn();

    DefineMeterAction::run(['code' => 'storage.gb', 'name' => 'Storage', 'aggregation' => 'average']);

    expect(app(MeterRepository::class)->listFor($project->tenant())[0]->aggregation)
        ->toBe(Aggregation::Sum);
});

it('refuses to define a meter for a role that may not shape the catalog', function (): void {
    $project = PanelSession::signIn('acme', Role::BillingOperator);

    DefineMeterAction::run(['code' => 'api.requests', 'name' => 'API requests', 'aggregation' => 'sum']);

    expect(app(MeterRepository::class)->listFor($project->tenant()))->toBe([]);
});

it('registers a customer from the panel', function (): void {
    $project = PanelSession::signIn();

    RegisterCustomerAction::run(['reference' => 'cus_4471', 'name' => 'North Wind Ltd']);

    $customers = app(CustomerRepository::class)->listFor($project->tenant());

    expect($customers)->toHaveCount(1)
        ->and((string) $customers[0]->reference)->toBe('cus_4471')
        ->and($customers[0]->name)->toBe('North Wind Ltd');
});

it('shows one project’s catalog and never another tenant’s', function (): void {
    $acme = PanelSession::signIn();
    $rival = TenantFactory::tenant('north-wind');

    CatalogFactory::meter($acme->tenant(), 'ours.requests');
    CatalogFactory::meter($rival->tenant(), 'theirs.requests');
    CatalogFactory::customer($acme->tenant(), 'cus_ours');
    CatalogFactory::customer($rival->tenant(), 'cus_theirs');

    get('/admin/meters')
        ->assertOk()
        ->assertSee('ours.requests')
        ->assertDontSee('theirs.requests');

    get('/admin/customers')
        ->assertOk()
        ->assertSee('cus_ours')
        ->assertDontSee('cus_theirs');
});

it('shows a project’s meters, not the whole organization’s', function (): void {
    $production = PanelSession::signIn();
    $sandbox = TenantFactory::project($production->organizationId, 'sandbox');

    CatalogFactory::meter($production->tenant(), 'production.requests');
    CatalogFactory::meter($sandbox->tenant(), 'sandbox.requests');

    // Meters belong to a project, not to an organization: a colleague who
    // switches to the sandbox has to see the sandbox's catalog, or the panel
    // would describe the wrong environment.
    get('/admin/meters')
        ->assertOk()
        ->assertSee('production.requests')
        ->assertDontSee('sandbox.requests');
});
