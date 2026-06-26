<?php

declare(strict_types=1);

namespace Tests\Support;

use Tests\TestCase;

/**
 * Boots the application with demo mode on.
 *
 * Demo mode is read when configuration is built, which is before any test body
 * runs, so a test that needs it has to set it before the application is
 * created — hence a test case rather than a helper call.
 */
abstract class DemoModeTestCase extends TestCase
{
    protected function setUp(): void
    {
        self::setDemoMode('true');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        self::setDemoMode('false');
    }

    private static function setDemoMode(string $value): void
    {
        putenv('APP_DEMO=' . $value);
        $_ENV['APP_DEMO'] = $value;
        $_SERVER['APP_DEMO'] = $value;
    }
}
