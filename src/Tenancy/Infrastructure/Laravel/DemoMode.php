<?php

declare(strict_types=1);

namespace Metered\Tenancy\Infrastructure\Laravel;

use RuntimeException;

/**
 * Environments where demo mode (open sign-up, ADR-0016) is allowed. Enabled
 * anywhere else, the application refuses to boot.
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
