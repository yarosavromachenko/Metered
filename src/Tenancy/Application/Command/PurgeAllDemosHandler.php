<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Domain\OrganizationRepository;

/**
 * `demo:reset` (ADR-0016): purges every demo organization, the showcase
 * included, one transaction each. Non-demo organizations are never touched.
 */
final readonly class PurgeAllDemosHandler
{
    public function __construct(
        private OrganizationRepository $organizations,
        private PurgeDemoOrganizationHandler $purge,
    ) {}

    /**
     * @return list<Uuid> the organizations purged
     */
    public function handle(PurgeAllDemos $command): array
    {
        $demos = $this->organizations->demos();

        foreach ($demos as $organizationId) {
            $this->purge->handle(new PurgeDemoOrganization($organizationId, $command->actor));
        }

        return $demos;
    }
}
