<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Contract;

use SensitiveParameter;

/**
 * A demo tenant wants something to look at: it has just signed up, or its
 * owner has wiped it to start again (ADR-0016).
 *
 * Tenancy does not know how a demo is filled — that is the simulation's
 * business, and the simulation is not even present outside a demo — so it
 * says so and leaves it there. The token is a key of the tenant's with the
 * scopes filling it needs; whoever handles this must not log or store it.
 */
final readonly class DemoDataRequested
{
    public function __construct(
        public string $organizationId,
        #[SensitiveParameter]
        public string $token,
    ) {}
}
