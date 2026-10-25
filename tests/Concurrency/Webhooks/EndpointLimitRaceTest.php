<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Metered\Tenancy\Domain\Project;
use Metered\Tenancy\Domain\Role;
use Metered\Webhooks\Application\Command\RegisterEndpoint;
use Metered\Webhooks\Application\Command\RegisterEndpointHandler;
use Metered\Webhooks\Domain\Exception\InvalidEndpoint;
use Spatie\Fork\Fork;
use Tests\Support\CommittedRows;
use Tests\Support\TenantFactory;

/*
 * Registrations racing for a project's last endpoint place, each in its own
 * process and committing: counting before inserting would let several of them
 * through, and the trigger's lock is what admits one.
 */

const ENDPOINT_LIMIT_RACE_ACTOR = 'test:webhook-endpoint-limit-race';

$project = null;

beforeEach(function () use (&$project): void {
    $project = TenantFactory::tenant('endpoint-limit-race-' . bin2hex(random_bytes(4)));
});

afterEach(function () use (&$project): void {
    if ($project instanceof Project) {
        CommittedRows::purgeOrganization($project->organizationId, ENDPOINT_LIMIT_RACE_ACTOR);
    }
});

it('admits exactly one of the registrations racing for the last place', function () use (&$project): void {
    assert($project instanceof Project);
    $actor = TenantFactory::member($project->organizationId, Role::Admin, 'limit-race-' . bin2hex(random_bytes(4)) . '@example.com');
    $register = static fn(string $path): string => app(RegisterEndpointHandler::class)->handle(new RegisterEndpoint(
        $project->tenant(),
        'https://race.example.com/' . $path,
        '',
        ['invoice.paid'],
        $actor,
    ))->endpoint->id->value;

    foreach (range(1, 9) as $index) {
        $register('kept-' . $index);
    }

    $attempts = array_map(
        static fn(int $index): Closure => static function () use ($register, $index): string {
            DB::purge(testConnection());

            try {
                $register('racing-' . $index);

                return 'registered';
            } catch (InvalidEndpoint) {
                return 'refused';
            } catch (Throwable $failure) {
                return $failure->getMessage();
            }
        },
        range(1, 8),
    );

    /** @var list<string> $outcomes */
    $outcomes = Fork::new()->run(...$attempts);

    expect(array_count_values($outcomes))->toEqualCanonicalizing(['registered' => 1, 'refused' => 7])
        ->and(DB::table('webhook_endpoints')->where('project_id', $project->id->value)->count())->toBe(10);
});
