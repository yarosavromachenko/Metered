<?php

declare(strict_types=1);

namespace Metered\Tenancy\Infrastructure\Laravel;

use RuntimeException;

/**
 * Where demo mode may be switched on at all.
 *
 * Demo mode opens sign-up to strangers and gives each of them a tenant that
 * is deleted a week after they stop coming back (ADR-0016). That is a
 * reasonable thing for a laptop running `make demo` and an unreasonable one
 * for anything holding real customers, so the environment decides, not a
 * convention: set anywhere else, the application refuses to boot. A
 * forgotten line in a production .env must fail loudly, not open a door.
 *
 * `testing` is on the list because the test suite is where demo mode is
 * proven to behave; a suite that cannot switch it on cannot test it.
 */
final class DemoMode
{
    /** @var list<string> */
    public const array ENVIRONMENTS = ['local', 'demo', 'testing'];

    public static function assertAllowed(bool $enabled, string $environment): void
    {
        if ($enabled && ! in_array($environment, self::ENVIRONMENTS, true)) {
            throw new RuntimeException(sprintf(
                'APP_DEMO is on in the "%s" environment. Demo mode opens sign-up to anyone and is refused anywhere but %s.',
                $environment,
                implode(', ', self::ENVIRONMENTS),
            ));
        }
    }
}
