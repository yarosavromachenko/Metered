<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test case bindings
|--------------------------------------------------------------------------
|
| Unit tests get no framework at all: domain logic must be constructible
| without booting Laravel, and binding a TestCase here would quietly hide the
| day that stops being true.
|
| Concurrency tests boot the framework but must never be wrapped in a
| transaction — they run real parallel connections, and a wrapping transaction
| would make every race they exist to catch invisible.
|
*/

pest()->extend(TestCase::class)->in('Feature', 'Integration', 'Concurrency');

// Integration tests get a clean schema per test, inside a transaction that is
// rolled back afterwards. Concurrency tests deliberately do not: they need real
// committed state visible to a second connection.
uses(RefreshDatabase::class)->in('Integration');

/**
 * The connection the suite is configured to use. Tests never name a connection
 * literally: the one thing worse than a test hitting the wrong database is a
 * test that hides which database it hit.
 */
function testConnection(): string
{
    $connection = config('database.default');

    return is_string($connection) ? $connection : 'pgsql_testing';
}
