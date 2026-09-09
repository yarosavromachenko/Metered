<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Domain\OrganizationRepository;

/**
 * `demo:reset`: a demo instance back to nothing but its real organizations,
 * so `make demo-reset` can seed the showcase again from a clean slate
 * (ADR-0016).
 *
 * Unlike the idle sweep it spares no demo — the showcase is purged to be
 * rebuilt — and like it, one transaction per organization: a purge that
 * fails leaves that tenant whole, and the rest still go. Organizations
 * created with `org:create` are not demos and are never touched; the
 * database refuses to delete their invoices even if asked.
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
