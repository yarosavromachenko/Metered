<?php

declare(strict_types=1);

namespace Metered\Tenancy\Presentation\Console;

use Illuminate\Console\Command;
use Metered\Shared\Domain\Access\Actor;
use Metered\Tenancy\Application\Command\PurgeAllDemos;
use Metered\Tenancy\Application\Command\PurgeAllDemosHandler;

/**
 * Deletes every demo organization — the showcase and every visitor's
 * tenant — with everything they held. `make demo-reset` runs it and then
 * seeds the showcase again. Organizations created with `org:create` stay.
 */
final class ResetDemosCommand extends Command
{
    protected $signature = 'demo:reset
        {--force : Do not ask for confirmation}';

    protected $description = 'Delete every demo organization, the showcase included, with all their data';

    public function handle(PurgeAllDemosHandler $handler): int
    {
        if ($this->option('force') !== true && ! $this->confirm('Delete every demo organization and all their data?')) {
            $this->components->warn('Nothing was deleted.');

            return self::FAILURE;
        }

        $purged = $handler->handle(new PurgeAllDemos(Actor::system('console:demo:reset')));

        $this->components->info(sprintf('Purged %d demo organization(s).', count($purged)));

        foreach ($purged as $organizationId) {
            $this->line('  ' . $organizationId->value);
        }

        return self::SUCCESS;
    }
}
