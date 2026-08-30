<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Metered\Billing\Application\Command\CancelSubscription;
use Metered\Billing\Application\Command\CancelSubscriptionHandler;
use Metered\Billing\Application\Command\CatalogNotFound;
use Metered\Billing\Application\Command\ChangeSubscriptionPlan;
use Metered\Billing\Application\Command\ChangeSubscriptionPlanHandler;
use Metered\Billing\Application\Command\StartSubscription;
use Metered\Billing\Application\Command\StartSubscriptionHandler;
use Metered\Billing\Domain\Exception\SubscriptionChangeRefused;
use Metered\Billing\Domain\Subscription\Subscription;
use Metered\Billing\Domain\Subscription\SubscriptionRepository;
use Metered\Billing\Domain\Subscription\SubscriptionStatus;
use Metered\Shared\Domain\Access\PermissionDenied;
use Metered\Tenancy\Domain\Role;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Tests\Support\CatalogFactory;
use Tests\Support\TenantFactory;

it('starts a subscription, changes its plan at the period end, then cancels it there', function (): void {
    $clock = new MockClock('2026-01-31 14:00:00', 'UTC');
    app()->instance(ClockInterface::class, $clock);

    $project = TenantFactory::tenant();
    $tenant = $project->tenant();
    $actor = TenantFactory::member($project->organizationId, Role::Admin);
    $customer = CatalogFactory::customer($tenant);
    $starter = CatalogFactory::version(CatalogFactory::plan($tenant, 'starter'));
    $pro = CatalogFactory::version(CatalogFactory::plan($tenant, 'pro'));

    $started = app(StartSubscriptionHandler::class)->handle(new StartSubscription($tenant, $customer->id, $starter->id, $actor));

    $clock->modify('2026-02-15 09:00:00');
    app(ChangeSubscriptionPlanHandler::class)->handle(new ChangeSubscriptionPlan($tenant, $started->id, $pro->id, $actor));

    $clock->modify('2026-03-05 00:00:00');
    app(CancelSubscriptionHandler::class)->handle(new CancelSubscription($tenant, $started->id, false, $actor));

    $stored = app(SubscriptionRepository::class)->find($tenant, $started->id);

    expect($stored?->status)->toBe(SubscriptionStatus::PendingCancellation)
        ->and($stored?->endsAt?->format(DATE_ATOM))->toBe('2026-03-31T14:00:00+00:00')
        ->and($stored?->versionAt(new DateTimeImmutable('2026-02-27T00:00:00+00:00'))?->value)->toBe($starter->id->value)
        ->and($stored?->versionAt(new DateTimeImmutable('2026-03-01T00:00:00+00:00'))?->value)->toBe($pro->id->value)
        ->and(DB::table('audit_log')->where('subject_id', $started->id->value)->orderBy('id')->pluck('action')->all())
        ->toBe(['subscription.started', 'subscription.plan_changed', 'subscription.canceled']);
});

it('cancels at once when asked to', function (): void {
    $project = TenantFactory::tenant();
    $tenant = $project->tenant();
    $actor = TenantFactory::member($project->organizationId, Role::Admin);
    $subscription = CatalogFactory::subscription(CatalogFactory::customer($tenant), CatalogFactory::version(CatalogFactory::plan($tenant)));

    $canceled = app(CancelSubscriptionHandler::class)->handle(new CancelSubscription($tenant, $subscription->id, true, $actor));

    expect($canceled->status)->toBe(SubscriptionStatus::Canceled)
        ->and(app(SubscriptionRepository::class)->find($tenant, $subscription->id)?->status)->toBe(SubscriptionStatus::Canceled);
});

it('refuses to subscribe to a draft', function (): void {
    $project = TenantFactory::tenant();
    $tenant = $project->tenant();
    $actor = TenantFactory::member($project->organizationId, Role::Admin);
    $draft = CatalogFactory::version(CatalogFactory::plan($tenant), published: false);

    expect(static fn(): Subscription => app(StartSubscriptionHandler::class)->handle(new StartSubscription($tenant, CatalogFactory::customer($tenant)->id, $draft->id, $actor)))
        ->toThrow(SubscriptionChangeRefused::class, 'is a draft');
});

it('answers not found for another project\'s customer, version and subscription alike', function (): void {
    $acme = TenantFactory::tenant('acme');
    $tenant = $acme->tenant();
    $actor = TenantFactory::member($acme->organizationId, Role::Admin);
    $rival = TenantFactory::tenant('north-wind')->tenant();
    $theirVersion = CatalogFactory::version(CatalogFactory::plan($rival));
    $theirCustomer = CatalogFactory::customer($rival);
    $theirSubscription = CatalogFactory::subscription($theirCustomer, $theirVersion);
    $ourCustomer = CatalogFactory::customer($tenant);
    $ourVersion = CatalogFactory::version(CatalogFactory::plan($tenant));
    $ourSubscription = CatalogFactory::subscription($ourCustomer, $ourVersion);

    expect(static fn() => app(StartSubscriptionHandler::class)->handle(new StartSubscription($tenant, $theirCustomer->id, $ourVersion->id, $actor)))
        ->toThrow(CatalogNotFound::class, 'No customer ')
        ->and(static fn() => app(StartSubscriptionHandler::class)->handle(new StartSubscription($tenant, $ourCustomer->id, $theirVersion->id, $actor)))
        ->toThrow(CatalogNotFound::class, 'No plan version ')
        ->and(static fn() => app(ChangeSubscriptionPlanHandler::class)->handle(new ChangeSubscriptionPlan($tenant, $theirSubscription->id, $ourVersion->id, $actor)))
        ->toThrow(CatalogNotFound::class, 'No subscription ')
        ->and(static fn() => app(ChangeSubscriptionPlanHandler::class)->handle(new ChangeSubscriptionPlan($tenant, $ourSubscription->id, $theirVersion->id, $actor)))
        ->toThrow(CatalogNotFound::class, 'No plan version ')
        ->and(static fn() => app(CancelSubscriptionHandler::class)->handle(new CancelSubscription($tenant, $theirSubscription->id, true, $actor)))
        ->toThrow(CatalogNotFound::class, 'No subscription ');
});

it('lets a viewer change no subscription', function (): void {
    $project = TenantFactory::tenant();
    $tenant = $project->tenant();
    $viewer = TenantFactory::member($project->organizationId, Role::Viewer);
    $subscription = CatalogFactory::subscription(CatalogFactory::customer($tenant), CatalogFactory::version(CatalogFactory::plan($tenant)));

    expect(static fn() => app(CancelSubscriptionHandler::class)->handle(new CancelSubscription($tenant, $subscription->id, true, $viewer)))
        ->toThrow(PermissionDenied::class)
        ->and(static fn() => app(StartSubscriptionHandler::class)->handle(new StartSubscription($tenant, $subscription->customerId, $subscription->phases[0]->planVersionId, $viewer)))
        ->toThrow(PermissionDenied::class);
});

it('announces a subscription starting and ending through the outbox', function (): void {
    $clock = new MockClock('2026-01-31 14:00:00', 'UTC');
    app()->instance(ClockInterface::class, $clock);
    $project = TenantFactory::tenant();
    $tenant = $project->tenant();
    $actor = TenantFactory::member($project->organizationId, Role::Admin);
    $customer = CatalogFactory::customer($tenant);
    $version = CatalogFactory::version(CatalogFactory::plan($tenant));

    $started = app(StartSubscriptionHandler::class)->handle(new StartSubscription($tenant, $customer->id, $version->id, $actor));
    $clock->modify('2026-02-10 09:00:00');
    app(CancelSubscriptionHandler::class)->handle(new CancelSubscription($tenant, $started->id, false, $actor));

    $types = DB::table('outbox_messages')->where('aggregate_id', $started->id->value)->orderBy('occurred_at')->pluck('type')->all();
    $payload = DB::table('outbox_messages')->where('aggregate_id', $started->id->value)->where('type', 'subscription.canceled')->value('payload');
    $canceled = json_decode(is_string($payload) ? $payload : '{}', true);

    expect($types)->toBe(['subscription.created', 'subscription.canceled'])
        ->and(DB::table('outbox_messages')->where('aggregate_id', $started->id->value)->value('aggregate_type'))->toBe('subscription')
        ->and($canceled)->toMatchArray([
            'subscription_id' => $started->id->value,
            'project_id' => $tenant->projectId->value,
            'organization_id' => $tenant->organizationId->value,
            'customer_id' => $customer->id->value,
            'status' => 'pending_cancellation',
            'plan_version_id' => $version->id->value,
            'ends_at' => '2026-02-28T14:00:00+00:00',
        ]);
});

it('backdates a subscription, anchoring it where it was asked to start', function (): void {
    app()->instance(ClockInterface::class, new MockClock('2026-05-10 12:00:00', 'UTC'));
    $project = TenantFactory::tenant();
    $tenant = $project->tenant();
    $actor = TenantFactory::member($project->organizationId, Role::Admin);
    $version = CatalogFactory::version(CatalogFactory::plan($tenant));

    $started = app(StartSubscriptionHandler::class)->handle(new StartSubscription(
        $tenant,
        CatalogFactory::customer($tenant)->id,
        $version->id,
        $actor,
        new DateTimeImmutable('2026-01-31T09:30:00+02:00'),
    ));

    $audited = DB::table('audit_log')->where('subject_id', $started->id->value);
    $occurredAt = $audited->clone()->value('occurred_at');
    $payload = $audited->clone()->value('payload');

    expect($started->anchorAt->format(DATE_ATOM))->toBe('2026-01-31T07:30:00+00:00')
        ->and(app(SubscriptionRepository::class)->find($tenant, $started->id)?->anchorAt->format(DATE_ATOM))->toBe('2026-01-31T07:30:00+00:00')
        // Audited when it was done, with where it was anchored.
        ->and(is_string($occurredAt) ? substr($occurredAt, 0, 19) : null)->toBe('2026-05-10 12:00:00')
        ->and(is_string($payload) ? json_decode($payload, true) : null)->toHaveKey('anchor_at', '2026-01-31T07:30:00+00:00');
});

it('backdates as far as a year and a day, and no further, and never into the future', function (string $startsAt, ?string $refusal): void {
    app()->instance(ClockInterface::class, new MockClock('2026-05-10 12:00:00', 'UTC'));
    $project = TenantFactory::tenant();
    $tenant = $project->tenant();
    $start = static fn(): Subscription => app(StartSubscriptionHandler::class)->handle(new StartSubscription(
        $tenant,
        CatalogFactory::customer($tenant)->id,
        CatalogFactory::version(CatalogFactory::plan($tenant))->id,
        TenantFactory::member($project->organizationId, Role::Admin),
        new DateTimeImmutable($startsAt),
    ));

    if ($refusal === null) {
        expect($start()->anchorAt->format(DATE_ATOM))->toBe(new DateTimeImmutable($startsAt)->format(DATE_ATOM));
    } else {
        expect($start)->toThrow(SubscriptionChangeRefused::class, $refusal);
    }
})->with([
    'now' => ['2026-05-10T12:00:00Z', null],
    '366 days back' => ['2025-05-09T12:00:00Z', null],
    'a second further' => ['2025-05-09T11:59:59Z', 'at most 366 days in the past'],
    'a second ahead' => ['2026-05-10T12:00:01Z', 'starts now or in the past'],
]);
