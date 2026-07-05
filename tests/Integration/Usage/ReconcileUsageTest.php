<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Metering\Aggregation;
use Metered\Shared\Domain\Quantity\Quantity;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Usage\Infrastructure\Persistence\Drift;
use Metered\Usage\Infrastructure\Persistence\UsageReconciler;
use Tests\Support\CatalogFactory;
use Tests\Support\TenantFactory;

/**
 * The check behind the claim the ingestion design makes: an aggregate always
 * equals the fold of the events under it.
 *
 * The events here are inserted directly rather than sent through the stream.
 * That is the point — this has to catch an aggregate that disagrees with its
 * events however it came to disagree, including ways the consumer cannot
 * produce.
 */
function reconciler(): UsageReconciler
{
    return app(UsageReconciler::class);
}

/**
 * @return array{tenant: TenantContext, meter: string, customer: string}
 */
function reconcilable(Aggregation $aggregation = Aggregation::Sum, string $slug = 'acme'): array
{
    $project = TenantFactory::tenant($slug);
    $meter = CatalogFactory::meter($project->tenant(), 'api.requests', $aggregation);
    $customer = CatalogFactory::customer($project->tenant(), 'cus_4471');

    return [
        'tenant' => $project->tenant(),
        'meter' => $meter->id->value,
        'customer' => $customer->id->value,
    ];
}

/**
 * @param  array{tenant: TenantContext, meter: string, customer: string}  $fixture
 */
function insertEvent(array $fixture, string $eventId, string $quantity, string $occurredAt): void
{
    DB::table('usage_events')->insert([
        'id' => app(IdentifierGenerator::class)->generate()->value,
        'organization_id' => $fixture['tenant']->organizationId->value,
        'project_id' => $fixture['tenant']->projectId->value,
        'event_id' => $eventId,
        'customer_id' => $fixture['customer'],
        'meter_id' => $fixture['meter'],
        'meter_code' => 'api.requests',
        'customer_ref' => 'cus_4471',
        'quantity' => $quantity,
        'occurred_at' => $occurredAt,
        'received_at' => $occurredAt,
        'properties' => '{}',
    ]);
}

/**
 * @param  array{tenant: TenantContext, meter: string, customer: string}  $fixture
 */
function insertAggregate(array $fixture, string $bucket, string $quantity, int $count): void
{
    DB::table('usage_aggregates')->insert([
        'organization_id' => $fixture['tenant']->organizationId->value,
        'project_id' => $fixture['tenant']->projectId->value,
        'customer_id' => $fixture['customer'],
        'meter_id' => $fixture['meter'],
        'meter_code' => 'api.requests',
        'customer_ref' => 'cus_4471',
        'bucket_start' => $bucket,
        'quantity' => $quantity,
        'event_count' => $count,
        'updated_at' => $bucket,
    ]);
}

/**
 * @return array{DateTimeImmutable, DateTimeImmutable}
 */
function reconcileWindow(): array
{
    return [new DateTimeImmutable('2026-09-22T00:00:00+00:00'), new DateTimeImmutable('2026-09-23T00:00:00+00:00')];
}

it('finds nothing to report when the aggregate matches its events', function (): void {
    $fixture = reconcilable();

    insertEvent($fixture, 'evt_1', '2.500000', '2026-09-22T10:10:00+00:00');
    insertEvent($fixture, 'evt_2', '1.500000', '2026-09-22T10:40:00+00:00');
    insertAggregate($fixture, '2026-09-22T10:00:00+00:00', '4.000000', 2);

    expect(reconciler()->check($fixture['tenant'], ...reconcileWindow()))->toBe([]);
});

it('reports an aggregate that disagrees with the events under it', function (): void {
    $fixture = reconcilable();

    insertEvent($fixture, 'evt_1', '2.500000', '2026-09-22T10:10:00+00:00');
    insertAggregate($fixture, '2026-09-22T10:00:00+00:00', '9.000000', 1);

    $drift = reconciler()->check($fixture['tenant'], ...reconcileWindow());

    expect($drift)->toHaveCount(1)
        ->and($drift[0]->kind)->toBe(Drift::MISMATCH)
        ->and($drift[0]->describe())->toContain('events say 2.500000');
});

it('reports events with no aggregate at all', function (): void {
    $fixture = reconcilable();

    insertEvent($fixture, 'evt_1', '2.500000', '2026-09-22T10:10:00+00:00');

    $drift = reconciler()->check($fixture['tenant'], ...reconcileWindow());

    expect($drift[0]->kind)->toBe(Drift::MISSING)
        ->and($drift[0]->recomputedCount)->toBe(1);
});

it('reports an aggregate with no events under it', function (): void {
    $fixture = reconcilable();

    insertAggregate($fixture, '2026-09-22T10:00:00+00:00', '4.000000', 2);

    $drift = reconciler()->check($fixture['tenant'], ...reconcileWindow());

    expect($drift[0]->kind)->toBe(Drift::EXTRA)
        ->and($drift[0]->describe())->toContain('no events under it');
});

it('folds in SQL exactly as the domain folds in PHP', function (Aggregation $aggregation, string $expected): void {
    $fixture = reconcilable($aggregation);

    insertEvent($fixture, 'evt_1', '3.000000', '2026-09-22T10:10:00+00:00');
    insertEvent($fixture, 'evt_2', '11.000000', '2026-09-22T10:20:00+00:00');
    insertEvent($fixture, 'evt_3', '7.000000', '2026-09-22T10:30:00+00:00');

    // The same three events, folded by the enum in PHP. Two expressions of one
    // rule is a risk worth a test: a change to either that is not made to the
    // other fails here rather than drifting into an invoice.
    $folded = Quantity::zero();

    foreach (['3', '11', '7'] as $quantity) {
        $folded = $aggregation->fold($folded, Quantity::fromString($quantity));
    }

    $drift = reconciler()->check($fixture['tenant'], ...reconcileWindow());

    expect($drift[0]->recomputedQuantity)->toBe($expected)
        ->and((string) $folded)->toBe($expected);
})->with([
    'sum' => [Aggregation::Sum, '21.000000'],
    'count' => [Aggregation::Count, '3.000000'],
    'max' => [Aggregation::Max, '11.000000'],
]);

it('ignores what lies outside the window it was asked about', function (): void {
    $fixture = reconcilable();

    insertEvent($fixture, 'evt_yesterday', '2.500000', '2026-09-21T10:10:00+00:00');

    expect(reconciler()->check($fixture['tenant'], ...reconcileWindow()))->toBe([]);
});

it('never reports one tenant’s events against another tenant’s aggregates', function (): void {
    $acme = reconcilable(slug: 'acme');
    $rival = reconcilable(slug: 'north-wind');

    insertEvent($acme, 'evt_1', '2.500000', '2026-09-22T10:10:00+00:00');
    insertAggregate($acme, '2026-09-22T10:00:00+00:00', '2.500000', 1);

    expect(reconciler()->check($rival['tenant'], ...reconcileWindow()))->toBe([]);
});

it('rewrites the window from the events when asked to repair it', function (): void {
    $fixture = reconcilable();

    insertEvent($fixture, 'evt_1', '2.500000', '2026-09-22T10:10:00+00:00');
    insertAggregate($fixture, '2026-09-22T10:00:00+00:00', '9.000000', 1);

    $rows = reconciler()->repair($fixture['tenant'], ...reconcileWindow());

    expect($rows)->toBe(1)
        ->and(reconciler()->check($fixture['tenant'], ...reconcileWindow()))->toBe([])
        ->and(DB::table('usage_aggregates')->where('project_id', $fixture['tenant']->projectId->value)->value('quantity'))
        ->toBe('2.500000');
});

it('exits non-zero on drift, so a chaos run can end on it', function (): void {
    $fixture = reconcilable();

    insertEvent($fixture, 'evt_1', '2.500000', '2026-09-22T10:10:00+00:00');

    $status = Artisan::call('usage:reconcile', [
        '--project' => $fixture['tenant']->projectId->value,
        '--from' => '2026-09-22T00:00:00+00:00',
        '--to' => '2026-09-23T00:00:00+00:00',
    ]);

    expect($status)->toBe(1)
        ->and(Artisan::output())->toContain('disagree with their events');
});

it('exits zero and says so when everything matches', function (): void {
    $fixture = reconcilable();

    insertEvent($fixture, 'evt_1', '2.500000', '2026-09-22T10:10:00+00:00');
    insertAggregate($fixture, '2026-09-22T10:00:00+00:00', '2.500000', 1);

    $status = Artisan::call('usage:reconcile', [
        '--from' => '2026-09-22T00:00:00+00:00',
        '--to' => '2026-09-23T00:00:00+00:00',
    ]);

    expect($status)->toBe(0)
        ->and(Artisan::output())->toContain('No drift');
});
