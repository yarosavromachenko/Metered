<?php

declare(strict_types=1);

namespace Metered\Tenancy\Presentation\Console;

use Illuminate\Console\Command;
use Metered\Shared\Domain\Access\Actor;
use Metered\Tenancy\Application\Command\PurgeIdleDemos;
use Metered\Tenancy\Application\Command\PurgeIdleDemosHandler;

/**
 * Daily.
 */
final class PurgeIdleDemosCommand extends Command
{
    protected $signature = 'tenancy:purge-idle-demos';

    protected $description = 'Delete demo organizations nobody has signed in to within the idle period';

    public function handle(PurgeIdleDemosHandler $handler): int
    {
        $purged = $handler->handle(new PurgeIdleDemos(Actor::system('console:tenancy:purge-idle-demos')));

        $this->components->info(sprintf('Purged %d idle demo organization(s).', count($purged)));

        foreach ($purged as $organizationId) {
            $this->line('  ' . $organizationId->value);
        }

        return self::SUCCESS;
    }
}
