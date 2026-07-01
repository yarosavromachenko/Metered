<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Metered\Billing\Application\Command\CustomerReferenceTaken;
use Metered\Billing\Application\Command\DefineMeter;
use Metered\Billing\Application\Command\DefineMeterHandler;
use Metered\Billing\Application\Command\MeterCodeTaken;
use Metered\Billing\Application\Command\RegisterCustomer;
use Metered\Billing\Application\Command\RegisterCustomerHandler;
use Metered\Billing\Domain\Customer;
use Metered\Billing\Domain\Exception\InvalidMeterCode;
use Metered\Billing\Domain\Meter;
use Metered\Billing\Domain\MeterCode;
use Metered\Billing\Domain\MeterRepository;
use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Access\PermissionDenied;
use Metered\Shared\Domain\Metering\Aggregation;
use Metered\Tenancy\Domain\Role;
use Tests\Support\CatalogFactory;
use Tests\Support\TenantFactory;

it('defines a meter, and writes who defined it to the audit log', function (): void {
    $project = TenantFactory::tenant();
    $actor = TenantFactory::member($project->organizationId, Role::Admin);

    $meter = app(DefineMeterHandler::class)->handle(new DefineMeter(
        tenant: $project->tenant(),
        code: 'API.Requests',
        name: 'API requests',
        aggregation: Aggregation::Count,
        actor: $actor,
    ));

    $stored = app(MeterRepository::class)->findByCode($project->tenant(), MeterCode::fromString('api.requests'));
    $entry = DB::table('audit_log')->where('subject_id', $meter->id->value)->first();

    expect($stored?->id->value)->toBe($meter->id->value)
        ->and($stored?->aggregation)->toBe(Aggregation::Count)
        ->and($entry?->action)->toBe('meter.defined')
        ->and($entry?->actor)->toBe($actor->label);
});

it('refuses a second meter under a code the project already uses', function (): void {
    $project = TenantFactory::tenant();
    $actor = TenantFactory::member($project->organizationId, Role::Admin);
    CatalogFactory::meter($project->tenant(), 'api.requests');

    expect(static fn(): Meter => app(DefineMeterHandler::class)->handle(new DefineMeter(
        tenant: $project->tenant(),
        // Same meter, differently capitalised: the codes are normalised, so
        // this is the duplicate it looks like.
        code: 'API.REQUESTS',
        name: 'API requests',
        aggregation: Aggregation::Sum,
        actor: $actor,
    )))->toThrow(MeterCodeTaken::class, 'api.requests');
});

it('lets a role that may not shape the catalog do nothing at all', function (Role $role, bool $allowed): void {
    $project = TenantFactory::tenant();
    $actor = TenantFactory::member($project->organizationId, $role);

    $define = static fn(): Meter => app(DefineMeterHandler::class)->handle(new DefineMeter(
        tenant: $project->tenant(),
        code: 'api.requests',
        name: 'API requests',
        aggregation: Aggregation::Sum,
        actor: $actor,
    ));

    $allowed
        ? expect($define()->code->value)->toBe('api.requests')
        : expect($define)->toThrow(PermissionDenied::class, 'catalog.manage');
})->with([
    'an owner may' => [Role::Owner, true],
    'an admin may' => [Role::Admin, true],
    // Deciding what is measured and deciding what somebody owes are different
    // jobs, and the roles are deliberately not nested (ADR-0017).
    'a billing operator may not' => [Role::BillingOperator, false],
    'a viewer may not' => [Role::Viewer, false],
]);

it('refuses a code no client could ever send', function (): void {
    $project = TenantFactory::tenant();
    $actor = TenantFactory::member($project->organizationId, Role::Admin);

    expect(static fn(): Meter => app(DefineMeterHandler::class)->handle(new DefineMeter(
        tenant: $project->tenant(),
        code: 'API Requests',
        name: 'API requests',
        aggregation: Aggregation::Sum,
        actor: $actor,
    )))->toThrow(InvalidMeterCode::class);
});

it('registers a customer under the reference the tenant already uses', function (): void {
    $project = TenantFactory::tenant();
    $actor = TenantFactory::member($project->organizationId, Role::Admin);

    $customer = app(RegisterCustomerHandler::class)->handle(new RegisterCustomer(
        tenant: $project->tenant(),
        reference: '  cus_4471  ',
        name: 'North Wind Ltd',
        actor: $actor,
    ));

    $entry = DB::table('audit_log')->where('subject_id', $customer->id->value)->first();

    expect((string) $customer->reference)->toBe('cus_4471')
        ->and($entry?->action)->toBe('customer.registered');
});

it('refuses a second customer under a reference the project already uses', function (): void {
    $project = TenantFactory::tenant();
    $actor = TenantFactory::member($project->organizationId, Role::Admin);
    CatalogFactory::customer($project->tenant(), 'cus_4471');

    expect(static fn(): Customer => app(RegisterCustomerHandler::class)->handle(new RegisterCustomer(
        tenant: $project->tenant(),
        reference: 'cus_4471',
        name: 'North Wind Ltd',
        actor: $actor,
    )))->toThrow(CustomerReferenceTaken::class, 'cus_4471');
});

it('lets the console define a meter, because the operator answers to nobody here', function (): void {
    $project = TenantFactory::tenant();

    // Seeding and simulation run without a person behind them. They are
    // trusted by virtue of being able to run at all (see Actor::system).
    $meter = app(DefineMeterHandler::class)->handle(new DefineMeter(
        tenant: $project->tenant(),
        code: 'storage.gb',
        name: 'Stored gigabytes',
        aggregation: Aggregation::Max,
        actor: Actor::system('console:sim:seed'),
    ));

    expect($meter->aggregation)->toBe(Aggregation::Max);
});
