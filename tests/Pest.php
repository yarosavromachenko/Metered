<?php

declare(strict_types=1);

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
