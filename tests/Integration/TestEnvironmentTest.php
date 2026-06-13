<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * Guards against the suite quietly running somewhere it should not.
 *
 * Both of these have already happened. The first time, container environment
 * variables beat PHPUnit's and the suite migrated the development database.
 * The second time, CI pinned DB_CONNECTION, so tests wrote on one connection
 * while code resolved from the container read from another — same database,
 * different session, and a test's uncommitted rows invisible to the thing
 * under test.
 *
 * Neither failure announced itself. Both took a while to find. These
 * assertions turn either into an immediate, obvious failure.
 *
 * They live in Integration rather than Architecture because they need a booted
 * application: the Architecture suite is bound to no TestCase, and there the
 * first of them would fail for the wrong reason.
 */

it('runs on the connection reserved for tests', function (): void {
    expect(testConnection())->toBe('pgsql_testing');
});

it('runs against the test database and not the development one', function (): void {
    // A prefix match, not an equality: under --parallel (which is how mutation
    // testing runs) Laravel gives each worker its own database, named after
    // this one with _test_1, _test_2 … appended, in the configuration as well
    // as on the connection. The development database is called `metered`, so
    // the prefix still tells the two apart.
    expect(DB::connection()->getDatabaseName())->toStartWith('metered_testing');
});

it('resolves the same connection from the container as the tests use', function (): void {
    // The relay and anything else configured with a connection name must end
    // up on the very connection a test is writing through, or it will not see
    // what the test just wrote.
    expect(config('metered.outbox.connection'))->toBe(testConnection())
        ->and(DB::connection(testConnection()))->toBe(DB::connection());
});
