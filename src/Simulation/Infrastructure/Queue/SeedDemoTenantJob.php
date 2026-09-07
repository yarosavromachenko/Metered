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
 * Fills a demo tenant with the small profile through the API, with the key
 * the tenant was given. Encrypted on the queue, because it carries that key.
 *
 * Tried once: a seed is not idempotent — a second run meets the first's
 * meters and plans and is refused — and a demo that half-filled is better
 * reset from the panel than filled twice.
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
            // Each tenant its own data, and the same data if it is reset.
            seed: crc32($this->organizationId),
            token: $this->token,
        ));
    }
}
