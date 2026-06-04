<?php

declare(strict_types=1);

/*
 * These tests enforce the rules from docs/engineering-guidelines.md that
 * Deptrac cannot express. Deptrac checks which namespace may reference which;
 * these check how the code inside a namespace is allowed to be written.
 */

/** Modules that have a full four-layer structure. */
const MODULES = ['Shared', 'Tenancy', 'Usage', 'Billing', 'Invoicing', 'Webhooks'];

/** Functions that hide a dependency instead of declaring it. */
const BANNED_HELPERS = [
    'now', 'today', 'time', 'date', 'mktime', 'microtime',
    'env', 'config', 'app', 'resolve', 'dispatch', 'event',
    'request', 'session', 'cookie', 'cache', 'logger', 'abort',
    'auth', 'url', 'route', 'redirect', 'response', 'view',
];

arch('the whole codebase declares strict types')
    ->expect('Metered')
    ->toUseStrictTypes();

arch('nothing in the codebase is left to debug output')
    ->expect(['dd', 'dump', 'var_dump', 'ray', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

foreach (MODULES as $module) {
    arch("{$module}\\Domain is free of the framework")
        ->expect("Metered\\{$module}\\Domain")
        ->not->toUse(['Illuminate', 'Laravel', 'Carbon']);

    arch("{$module}\\Application is free of the framework")
        ->expect("Metered\\{$module}\\Application")
        ->not->toUse(['Illuminate', 'Laravel', 'Carbon']);

    arch("{$module}\\Domain declares its dependencies instead of reaching for helpers")
        ->expect("Metered\\{$module}\\Domain")
        ->not->toUse(BANNED_HELPERS);

    arch("{$module}\\Application declares its dependencies instead of reaching for helpers")
        ->expect("Metered\\{$module}\\Application")
        ->not->toUse(BANNED_HELPERS);

    arch("{$module}\\Application does not reach into its own Infrastructure")
        ->expect("Metered\\{$module}\\Application")
        ->not->toUse("Metered\\{$module}\\Infrastructure");

    arch("{$module}\\Domain does not reach into Application or Infrastructure")
        ->expect("Metered\\{$module}\\Domain")
        ->not->toUse([
            "Metered\\{$module}\\Application",
            "Metered\\{$module}\\Infrastructure",
            "Metered\\{$module}\\Presentation",
        ]);
}

arch('the dev-only simulation module never leaks into the application')
    ->expect('Metered\Simulation')
    ->not->toBeUsedIn([
        'Metered\Shared', 'Metered\Tenancy', 'Metered\Usage',
        'Metered\Billing', 'Metered\Invoicing', 'Metered\Webhooks',
        'Metered\Admin', 'App',
    ]);
