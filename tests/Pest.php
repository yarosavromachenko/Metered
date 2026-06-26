<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DemoModeTestCase;
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

// Demo mode is read while configuration is built, so a test that needs it on
// has to say so before the application exists. That is a different base test
// case, and Pest binds those per directory — hence a suite of its own rather
// than a folder inside Integration.
pest()->extend(DemoModeTestCase::class)->in('Demo');

// Integration tests get a clean schema per test, inside a transaction that is
// rolled back afterwards. Concurrency tests deliberately do not: they need real
// committed state visible to a second connection.
uses(RefreshDatabase::class)->in('Integration', 'Demo');

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
