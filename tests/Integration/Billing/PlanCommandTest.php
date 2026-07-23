<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Metered\Billing\Application\Command\AddPrice;
use Metered\Billing\Application\Command\AddPriceHandler;
use Metered\Billing\Application\Command\CatalogNotFound;
use Metered\Billing\Application\Command\CreatePlan;
use Metered\Billing\Application\Command\CreatePlanHandler;
use Metered\Billing\Application\Command\DraftPlanVersion;
use Metered\Billing\Application\Command\DraftPlanVersionHandler;
use Metered\Billing\Application\Command\PlanCodeTaken;
use Metered\Billing\Application\Command\PublishPlanVersion;
use Metered\Billing\Application\Command\PublishPlanVersionHandler;
use Metered\Billing\Application\Command\RemovePrice;
use Metered\Billing\Application\Command\RemovePriceHandler;
use Metered\Billing\Domain\Exception\PlanVersionLocked;
use Metered\Billing\Domain\Period\BillingInterval;
use Metered\Billing\Domain\Plan\Plan;
use Metered\Billing\Domain\Plan\PlanVersion;
use Metered\Billing\Domain\Plan\PlanVersionRepository;
use Metered\Billing\Domain\Plan\Price;
use Metered\Billing\Domain\Pricing\FlatFee;
use Metered\Billing\Domain\Pricing\PerUnit;
use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Access\PermissionDenied;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Money\UnitPrice;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Domain\Role;
use Tests\Support\CatalogFactory;
use Tests\Support\TenantFactory;

it('creates a plan, drafts a version in the project\'s currency, prices and publishes it', function (): void {
    $project = TenantFactory::project(TenantFactory::organization('acme'), currency: 'USD');
    $tenant = $project->tenant();
    $actor = TenantFactory::member($project->organizationId, Role::Admin);
    $meter = CatalogFactory::meter($tenant, 'api.requests');

    $plan = app(CreatePlanHandler::class)->handle(new CreatePlan($tenant, 'Pro', 'Pro', $actor));
    $draft = app(DraftPlanVersionHandler::class)->handle(new DraftPlanVersion($tenant, $plan->id, BillingInterval::Month, $actor));

    app(AddPriceHandler::class)->handle(new AddPrice($tenant, $draft->id, FlatFee::of(Money::ofMinorUnits(4900, 'USD')), null, $actor));
    app(AddPriceHandler::class)->handle(new AddPrice($tenant, $draft->id, PerUnit::at(UnitPrice::fromString('0.002', 'USD')), $meter->id, $actor));
    $published = app(PublishPlanVersionHandler::class)->handle(new PublishPlanVersion($tenant, $draft->id, $actor));

    $stored = app(PlanVersionRepository::class)->find($tenant, $draft->id);

    expect((string) $plan->code)->toBe('pro')
        ->and($draft->currency)->toBe('USD')
        ->and($draft->number)->toBe(1)
        ->and($published->isPublished())->toBeTrue()
        ->and($stored?->isPublished())->toBeTrue()
        ->and(array_map(static fn(Price $p): ?string => $p->meterId?->value, $stored->prices ?? []))->toBe([null, $meter->id->value])
        ->and(DB::table('audit_log')->whereIn('subject_id', [$plan->id->value, $draft->id->value])->orderBy('id')->pluck('action')->all())
        ->toBe(['plan.created', 'plan_version.drafted', 'price.added', 'price.added', 'plan_version.published']);
});

it('refuses a second plan under a code the project already uses', function (): void {
    $project = TenantFactory::tenant();
    $actor = TenantFactory::member($project->organizationId, Role::Admin);
    CatalogFactory::plan($project->tenant(), 'pro');

    expect(static fn(): Plan => app(CreatePlanHandler::class)->handle(new CreatePlan($project->tenant(), 'PRO', 'Pro again', $actor)))
        ->toThrow(PlanCodeTaken::class, '"pro"');
});

it('removes a price from a draft, and refuses once the version is published', function (): void {
    $project = TenantFactory::tenant();
    $tenant = $project->tenant();
    $actor = TenantFactory::member($project->organizationId, Role::Admin);
    $draft = CatalogFactory::version(CatalogFactory::plan($tenant), published: false);
    $published = CatalogFactory::version(CatalogFactory::plan($tenant, 'team'));

    $emptied = app(RemovePriceHandler::class)->handle(new RemovePrice($tenant, $draft->id, $draft->prices[0]->id, $actor));

    expect($emptied->prices)->toBe([])
        ->and(app(PlanVersionRepository::class)->find($tenant, $draft->id)?->prices)->toBe([])
        ->and(static fn(): PlanVersion => app(RemovePriceHandler::class)->handle(new RemovePrice($tenant, $published->id, $published->prices[0]->id, $actor)))
        ->toThrow(PlanVersionLocked::class);
});

it('answers not found for another project\'s plan, version and meter alike', function (): void {
    $acme = TenantFactory::tenant('acme');
    $actor = TenantFactory::member($acme->organizationId, Role::Admin);
    $rival = TenantFactory::tenant('north-wind')->tenant();
    $theirPlan = CatalogFactory::plan($rival);
    $theirDraft = CatalogFactory::version($theirPlan, published: false);
    $theirMeter = CatalogFactory::meter($rival);
    $ourDraft = CatalogFactory::version(CatalogFactory::plan($acme->tenant()), published: false);

    expect(static fn() => app(DraftPlanVersionHandler::class)->handle(new DraftPlanVersion($acme->tenant(), $theirPlan->id, BillingInterval::Month, $actor)))
        ->toThrow(CatalogNotFound::class, 'No plan ')
        ->and(static fn() => app(PublishPlanVersionHandler::class)->handle(new PublishPlanVersion($acme->tenant(), $theirDraft->id, $actor)))
        ->toThrow(CatalogNotFound::class, 'No plan version ')
        ->and(static fn() => app(AddPriceHandler::class)->handle(new AddPrice($acme->tenant(), $theirDraft->id, FlatFee::of(Money::ofMinorUnits(1, 'EUR')), null, $actor)))
        ->toThrow(CatalogNotFound::class, 'No plan version ')
        ->and(static fn() => app(RemovePriceHandler::class)->handle(new RemovePrice($acme->tenant(), $theirDraft->id, $theirDraft->prices[0]->id, $actor)))
        ->toThrow(CatalogNotFound::class, 'No plan version ')
        ->and(static fn() => app(AddPriceHandler::class)->handle(new AddPrice($acme->tenant(), $ourDraft->id, PerUnit::at(UnitPrice::fromString('1', 'EUR')), $theirMeter->id, $actor)))
        ->toThrow(CatalogNotFound::class, 'No meter ');
});

it('lets only a role that manages the catalog change it', function (Closure $attempt): void {
    /** @var Closure(TenantContext, Actor):mixed $attempt */
    $project = TenantFactory::tenant();
    $viewer = TenantFactory::member($project->organizationId, Role::Viewer);

    expect(static fn(): mixed => $attempt($project->tenant(), $viewer))->toThrow(PermissionDenied::class);
})->with([
    'create a plan' => [static fn(TenantContext $tenant, Actor $actor): Plan => app(CreatePlanHandler::class)->handle(new CreatePlan($tenant, 'pro', 'Pro', $actor))],
    'draft a version' => [static fn(TenantContext $tenant, Actor $actor): PlanVersion => app(DraftPlanVersionHandler::class)->handle(new DraftPlanVersion($tenant, CatalogFactory::plan($tenant)->id, BillingInterval::Month, $actor))],
    'publish a version' => [static fn(TenantContext $tenant, Actor $actor): PlanVersion => app(PublishPlanVersionHandler::class)->handle(new PublishPlanVersion($tenant, CatalogFactory::version(CatalogFactory::plan($tenant), published: false)->id, $actor))],
]);
