<?php

declare(strict_types=1);

namespace Metered\Simulation\Infrastructure\Queue;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Metered\Simulation\Application\Seed\Profile;
use Metered\Simulation\Application\Seed\SeedTenant;
use Metered\Simulation\Application\Seed\SeedTenantHandler;
use SensitiveParameter;

/**
 * Small profile, through the API with the tenant's key; encrypted on the queue.
 * Single attempt: seeding is not idempotent, a half-filled demo is reset from
 * the panel.
 */
final class SeedDemoTenantJob implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 55;

    public function __construct(
        public readonly string $organizationId,
        #[SensitiveParameter]
        public readonly string $token,
    ) {
        $this->afterCommit();
    }

    public function handle(SeedTenantHandler $handler): void
    {
        $handler->handle(new SeedTenant(
            profile: Profile::Small,
            // Seeded per tenant: same data after a reset.
            seed: crc32($this->organizationId),
            token: $this->token,
        ));
    }
}
