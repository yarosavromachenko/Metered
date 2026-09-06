<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('creates a demo organization from the console when the instance is a demo', function (): void {
    expect(Artisan::call('org:create', ['name' => 'Northwind Cloud', '--demo' => true, '--json' => true]))->toBe(0)
        ->and(DB::table('organizations')->where('slug', 'northwind-cloud')->value('demo'))->toBeTrue();
});
