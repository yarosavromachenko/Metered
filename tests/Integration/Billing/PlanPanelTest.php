<?php

declare(strict_types=1);

use Metered\Billing\Domain\Plan\Plan;
use Metered\Billing\Domain\Plan\PlanRepository;
use Metered\Billing\Domain\Plan\PlanVersionRepository;
use Metered\Billing\Domain\Plan\Price;
use Metered\Billing\Domain\Subscription\SubscriptionRepository;
use Metered\Billing\Domain\Subscription\SubscriptionStatus;
use Metered\Billing\Presentation\Filament\Actions\AddPriceAction;
use Metered\Billing\Presentation\Filament\Actions\CancelSubscriptionAction;
use Metered\Billing\Presentation\Filament\Actions\ChangeSubscriptionPlanAction;
use Metered\Billing\Presentation\Filament\Actions\CreatePlanAction;
use Metered\Billing\Presentation\Filament\Actions\DraftPlanVersionAction;
use Metered\Billing\Presentation\Filament\Actions\PublishPlanVersionAction;
use Metered\Billing\Presentation\Filament\Actions\RemovePriceAction;
use Metered\Billing\Presentation\Filament\Actions\StartSubscriptionAction;
use Metered\Shared\Domain\Quantity\Quantity;
use Metered\Tenancy\Domain\Role;

use function Pest\Laravel\get;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Tests\Support\CatalogFactory;
use Tests\Support\PanelSession;
use Tests\Support\TenantFactory;

/**
 * Plans, versions and subscriptions in the panel. The actions are driven
 * through the same `run()` their buttons call; the pages are requested for
 * real, to hold the one rule every screen answers to — this project's rows
 * and no one else's.
 */
it('builds a plan from the panel: create, draft, price, publish', function (): void {
    $project = PanelSession::signIn();
    $tenant = $project->tenant();
    $meter = CatalogFactory::meter($tenant, 'api.requests');

    CreatePlanAction::run(['code' => 'Pro', 'name' => 'Pro']);
    $plan = app(PlanRepository::class)->listFor($tenant)[0];

    DraftPlanVersionAction::run($plan->id->value, ['interval' => 'month']);
    $version = app(PlanVersionRepository::class)->listForPlan($tenant, $plan->id)[0];

    AddPriceAction::run($version->id->value, 'EUR', ['model' => 'flat_fee', 'amount' => '49.00']);
    AddPriceAction::run($version->id->value, 'EUR', ['model' => 'graduated', 'meter' => $meter->id->value, 'tiers' => [
        ['up_to' => '1000', 'unit_price' => '0'],
        ['up_to' => '', 'unit_price' => '0.002'],
    ]]);
    PublishPlanVersionAction::run($version->id->value);

    $stored = app(PlanVersionRepository::class)->find($tenant, $version->id);

    expect($stored?->isPublished())->toBeTrue()
        ->and(array_map(static fn(Price $p): int => $p->charge(Quantity::fromString('1500'))->minorUnits(), $stored->prices ?? []))
        ->toBe([4900, 100]);
});

it('refuses an amount with more places than the currency has, rather than rounding it', function (): void {
    $project = PanelSession::signIn();
    $draft = CatalogFactory::version(CatalogFactory::plan($project->tenant()), prices: [], published: false);

    AddPriceAction::run($draft->id->value, 'EUR', ['model' => 'flat_fee', 'amount' => '49.001']);

    expect(app(PlanVersionRepository::class)->find($project->tenant(), $draft->id)?->prices)->toBe([]);
});

it('removes a price from a draft', function (): void {
    $project = PanelSession::signIn();
    $draft = CatalogFactory::version(CatalogFactory::plan($project->tenant()), published: false);

    RemovePriceAction::run($draft->id->value, ['price' => $draft->prices[0]->id->value]);

    expect(app(PlanVersionRepository::class)->find($project->tenant(), $draft->id)?->prices)->toBe([]);
});

it('runs a subscription from the panel: start, change plan, cancel', function (): void {
    app()->instance(ClockInterface::class, $clock = new MockClock('2026-01-31 14:00:00', 'UTC'));
    $project = PanelSession::signIn();
    $tenant = $project->tenant();
    $customer = CatalogFactory::customer($tenant);
    $starter = CatalogFactory::version(CatalogFactory::plan($tenant, 'starter'));
    $pro = CatalogFactory::version(CatalogFactory::plan($tenant, 'pro'));

    StartSubscriptionAction::run(['customer' => $customer->id->value, 'version' => $starter->id->value]);
    $subscription = app(SubscriptionRepository::class)->listForCustomer($tenant, $customer->id)[0];

    $clock->modify('2026-02-10 00:00:00');
    ChangeSubscriptionPlanAction::run($subscription->id->value, ['version' => $pro->id->value]);
    CancelSubscriptionAction::run($subscription->id->value, ['immediately' => false]);

    $stored = app(SubscriptionRepository::class)->find($tenant, $subscription->id);

    // Canceled at the end of February, the change that would have started
    // then is dropped with it.
    expect($stored?->status)->toBe(SubscriptionStatus::PendingCancellation)
        ->and($stored?->endsAt?->format(DATE_ATOM))->toBe('2026-02-28T14:00:00+00:00')
        ->and($stored?->phases)->toHaveCount(1);
});

it('changes nothing for a role that may not shape the catalog', function (): void {
    $project = PanelSession::signIn('acme', Role::BillingOperator);
    $tenant = $project->tenant();
    $draft = CatalogFactory::version(CatalogFactory::plan($tenant, 'team'), published: false);

    CreatePlanAction::run(['code' => 'pro', 'name' => 'Pro']);
    PublishPlanVersionAction::run($draft->id->value);
    StartSubscriptionAction::run(['customer' => CatalogFactory::customer($tenant)->id->value, 'version' => $draft->id->value]);

    expect(array_map(static fn(Plan $p): string => $p->code->value, app(PlanRepository::class)->listFor($tenant)))->toBe(['team'])
        ->and(app(PlanVersionRepository::class)->find($tenant, $draft->id)?->isPublished())->toBeFalse();
});

it('shows one project\'s plans, versions and subscriptions and never another tenant\'s', function (): void {
    $acme = PanelSession::signIn();
    $rival = TenantFactory::tenant('north-wind')->tenant();

    $ours = CatalogFactory::version(CatalogFactory::plan($acme->tenant(), 'ours-plan'));
    $theirs = CatalogFactory::version(CatalogFactory::plan($rival, 'theirs-plan'));
    CatalogFactory::subscription(CatalogFactory::customer($acme->tenant(), 'cus_ours'), $ours);
    CatalogFactory::subscription(CatalogFactory::customer($rival, 'cus_theirs'), $theirs);

    get('/admin/plans')->assertOk()->assertSee('ours-plan')->assertDontSee('theirs-plan');
    get('/admin/plan-versions')->assertOk()->assertSee('ours-plan')->assertSee('Flat fee 49.00 EUR per period')->assertDontSee('theirs-plan');
    get('/admin/subscriptions')->assertOk()->assertSee('cus_ours')->assertSee('ours-plan v1')->assertDontSee('cus_theirs');
});
