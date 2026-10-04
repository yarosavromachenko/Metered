<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use DateInterval;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Domain\OrganizationRepository;
use Metered\Tenancy\Domain\Slug;
use Psr\Clock\ClockInterface;

/**
 * Purges demo organizations with no sign-in for the idle period, a week by
 * default (ADR-0016), one transaction each. The showcase is skipped.
 */
final readonly class PurgeIdleDemosHandler
{
    public function __construct(
        private OrganizationRepository $organizations,
        private PurgeDemoOrganizationHandler $purge,
        private ClockInterface $clock,
        private int $idleSeconds,
        private ?Slug $showcase = null,
    ) {}

    /**
     * @return list<Uuid> the organizations purged
     */
    public function handle(PurgeIdleDemos $command): array
    {
        $cutoff = $this->clock->now()->sub(new DateInterval(sprintf('PT%dS', $this->idleSeconds)));
        $showcase = $this->showcase instanceof Slug ? $this->organizations->findBySlug($this->showcase)?->id : null;
        $idle = array_values(array_filter(
            $this->organizations->demos(idleSince: $cutoff),
            static fn(Uuid $id): bool => ! $showcase instanceof Uuid || ! $id->equals($showcase),
        ));

        foreach ($idle as $organizationId) {
            $this->purge->handle(new PurgeDemoOrganization($organizationId, $command->actor));
        }

        return $idle;
    }
}
