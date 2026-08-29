<?php

declare(strict_types=1);

use Metered\Tenancy\Infrastructure\Laravel\DemoMode;

it('lets demo mode on where a demo runs and where it is tested', function (string $environment): void {
    DemoMode::assertAllowed(true, $environment);

    expect(true)->toBeTrue();
})->with(['local', 'demo', 'testing']);

it('refuses demo mode anywhere else, so the application does not boot', function (string $environment): void {
    DemoMode::assertAllowed(true, $environment);
})->with(['production', 'staging', 'Local', ''])->throws(RuntimeException::class, 'APP_DEMO is on in the');

it('says nothing when demo mode is off, whatever the environment', function (string $environment): void {
    DemoMode::assertAllowed(false, $environment);

    expect(true)->toBeTrue();
})->with(['production', 'local']);
