<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Contract;

use SensitiveParameter;

/**
 * After a demo sign-up or reset; the Simulation module fills the tenant
 * (ADR-0016). The token is an API key: never log or store it.
 */
final readonly class DemoDataRequested
{
    public function __construct(
        public string $organizationId,
        #[SensitiveParameter]
        public string $token,
    ) {}
}
