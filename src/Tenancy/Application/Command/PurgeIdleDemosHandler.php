<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use DateInterval;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Domain\OrganizationRepository;
use Psr\Clock\ClockInterface;

/**
 * Demo tenants are deleted once nobody has signed in to them for the idle
 * period — a week by default (ADR-0016).
 *
 * One transaction per organization rather than one for all of them: a purge
 * that fails leaves that tenant whole and the rest of the sweep still runs.
 */
final readonly class PurgeIdleDemosHandler
{
    public function __construct(
        private OrganizationRepository $organizations,
        private PurgeDemoOrganizationHandler $purge,
        private ClockInterface $clock,
        private int $idleSeconds,
    ) {}

    /**
     * @return list<Uuid> the organizations purged
     */
    public function handle(PurgeIdleDemos $command): array
    {
        $cutoff = $this->clock->now()->sub(new DateInterval(sprintf('PT%dS', $this->idleSeconds)));
        $idle = $this->organizations->demosIdleSince($cutoff);

        foreach ($idle as $organizationId) {
            $this->purge->handle(new PurgeDemoOrganization($organizationId, $command->actor));
        }

        return $idle;
    }
}
