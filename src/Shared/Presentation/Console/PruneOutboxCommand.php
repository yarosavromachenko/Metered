<?php

declare(strict_types=1);

namespace Metered\Shared\Presentation\Console;

use Illuminate\Console\Command;
use Metered\Shared\Infrastructure\Outbox\OutboxPruner;

/**
 * Scheduled daily.
 */
final class PruneOutboxCommand extends Command
{
    protected $signature = 'outbox:prune
        {--days= : Keep published messages this many days; OUTBOX_RETENTION_DAYS by default}';

    protected $description = 'Remove outbox messages published longer ago than the retention window';

    public function handle(OutboxPruner $pruner): int
    {
        $days = $this->option('days') ?? (string) config()->integer('metered.outbox.retention_days', 7);

        if (! is_string($days) || ! ctype_digit($days) || (int) $days < 1) {
            $this->error('--days must be a positive whole number.');

            return self::FAILURE;
        }

        $removed = $pruner->prune((int) $days);

        $this->components->info(sprintf('Removed %d outbox message(s) published more than %d day(s) ago.', $removed, (int) $days));

        return self::SUCCESS;
    }
}
