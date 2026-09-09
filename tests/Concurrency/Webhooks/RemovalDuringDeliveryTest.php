<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Domain\Project;
use Metered\Webhooks\Domain\Endpoint\EndpointRepository;
use Metered\Webhooks\Infrastructure\Persistence\DatabaseWebhooksPurger;
use Spatie\Fork\Fork;
use Tests\Support\CommittedRows;
use Tests\Support\TenantFactory;

/*
 * A delivery worker locks the delivery it claims and then its endpoint
 * (AttemptDeliveryHandler). Removing endpoints while it does — one from the
 * panel, or all of a demo tenant's in a purge — must take those locks in the
 * same order; deleting the endpoint and letting the cascade reach its
 * deliveries takes them the other way round, and PostgreSQL then aborts one
 * of the two with a deadlock. `make demo-reset` met exactly that while the
 * showcase's deliveries were being attempted.
 *
 * The worker's side is played by hand, so the interleaving is the one that
 * deadlocks rather than whatever the scheduler picks: it holds the delivery,
 * waits long enough for the removal to begin, then asks for the endpoint.
 */

const REMOVAL_RACE_ACTOR = 'test:webhook-removal-race';

$project = null;

beforeEach(function () use (&$project): void {
    $project = TenantFactory::tenant('removal-race-' . bin2hex(random_bytes(4)));
});

afterEach(function () use (&$project): void {
    if ($project instanceof Project) {
        CommittedRows::purgeOrganization($project->organizationId, REMOVAL_RACE_ACTOR);
    }
});

/**
 * @return array{endpoint: string, delivery: string}
 */
function endpointWithPendingDelivery(Project $project): array
{
    $endpoint = (string) Str::uuid7();
    $delivery = (string) Str::uuid7();
    $tenant = ['organization_id' => $project->organizationId->value, 'project_id' => $project->id->value];

    DB::table('webhook_endpoints')->insert($tenant + [
        'id' => $endpoint, 'url' => 'https://race.example.com/in', 'description' => 'race', 'event_types' => '["invoice.paid"]',
        'secret' => 'whsec_' . str_repeat('a', 43), 'enabled' => true, 'created_at' => '2026-09-26 12:00:00+00',
    ]);
    DB::table('webhook_deliveries')->insert($tenant + [
        'id' => $delivery, 'endpoint_id' => $endpoint, 'event_id' => (string) Str::uuid7(), 'event_type' => 'invoice.paid',
        'body' => '{}', 'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => '2026-09-26 12:00:00+00',
        'created_at' => '2026-09-26 12:00:00+00',
    ]);

    return ['endpoint' => $endpoint, 'delivery' => $delivery];
}

/**
 * The worker's claim, and the removal started while the worker holds its
 * delivery. Each returns 'ok' or the error that stopped it — an exception in
 * a forked child is otherwise lost.
 *
 * @param  array{endpoint: string, delivery: string}  $rows
 * @param  Closure(): void  $removal
 *
 * @return list<string>
 */
function raceAgainstClaim(array $rows, Closure $removal): array
{
    $worker = static function () use ($rows): string {
        DB::purge(testConnection());

        try {
            DB::transaction(static function () use ($rows): void {
                DB::table('webhook_deliveries')->where('id', $rows['delivery'])->lockForUpdate()->first();
                usleep(300_000);
                DB::table('webhook_endpoints')->where('id', $rows['endpoint'])->lockForUpdate()->first();
            });

            return 'ok';
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    };

    $remover = static function () use ($removal): string {
        DB::purge(testConnection());
        usleep(100_000);

        try {
            DB::transaction($removal);

            return 'ok';
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    };

    /** @var list<string> */
    return Fork::new()->run($worker, $remover);
}

it('purges a tenant\'s endpoints while a delivery to one is being claimed', function () use (&$project): void {
    assert($project instanceof Project);
    $rows = endpointWithPendingDelivery($project);

    $results = raceAgainstClaim($rows, static function () use ($project): void {
        app(DatabaseWebhooksPurger::class)->purgeOrganization($project->organizationId, [$project->id]);
    });

    expect($results)->toBe(['ok', 'ok'])
        ->and(DB::table('webhook_endpoints')->where('id', $rows['endpoint'])->exists())->toBeFalse()
        ->and(DB::table('webhook_deliveries')->where('id', $rows['delivery'])->exists())->toBeFalse();
});

it('removes an endpoint while a delivery to it is being claimed', function () use (&$project): void {
    assert($project instanceof Project);
    $rows = endpointWithPendingDelivery($project);

    $results = raceAgainstClaim($rows, static function () use ($project, $rows): void {
        app(EndpointRepository::class)->remove($project->tenant(), Uuid::fromString($rows['endpoint']));
    });

    expect($results)->toBe(['ok', 'ok'])
        ->and(DB::table('webhook_endpoints')->where('id', $rows['endpoint'])->exists())->toBeFalse()
        ->and(DB::table('webhook_deliveries')->where('id', $rows['delivery'])->exists())->toBeFalse();
});
