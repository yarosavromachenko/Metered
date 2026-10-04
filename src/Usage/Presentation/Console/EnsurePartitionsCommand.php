<?php

declare(strict_types=1);

namespace Metered\Usage\Presentation\Console;

use DateInterval;
use Illuminate\Console\Command;
use Metered\Usage\Infrastructure\Persistence\PartitionManager;
use Psr\Clock\ClockInterface;

/**
 * Scheduled. Warns, without moving them, about rows already in the default
 * partition for a day being created.
 */
final class EnsurePartitionsCommand extends Command
{
    protected $signature = 'usage:partitions:ensure
        {--days= : How many days ahead to create, defaults to the configured window}
        {--back= : How many days back to create, when history older than the acceptance window is to be loaded}
        {--prune : Also drop partitions older than the retention window}
        {--rescue : Move rows stranded in the default partition into the day they belong to}';

    protected $description = 'Create the coming days\' usage partitions, and optionally drop expired ones';

    public function handle(PartitionManager $partitions, ClockInterface $clock): int
    {
        $now = $clock->now();
        $daysAhead = $this->intOption('days') ?? $this->config('metered.usage.partitions.days_ahead', 7);

        // Back as far as the acceptance window.
        $daysBack = (int) ceil($this->config('metered.usage.acceptance.max_age_seconds', 604800) / 86400);

        // Further back on request, for the history load of `sim:seed`.
        $daysBack = max($daysBack, $this->intOption('back') ?? 0);

        $created = $partitions->ensure($now, $daysBack, $daysAhead, $this->option('rescue') === true);

        $created === []
            ? $this->line('Partitions are up to date.')
            : $this->info(sprintf('Created %d partition(s): %s', count($created), implode(', ', $created)));

        if ($this->option('prune')) {
            $retention = $this->config('metered.usage.partitions.retention_days', 400);
            $dropped = $partitions->prune($now->sub(new DateInterval('P' . $retention . 'D')));

            $dropped === []
                ? $this->line('Nothing past retention.')
                : $this->warn(sprintf('Dropped %d partition(s): %s', count($dropped), implode(', ', $dropped)));
        }

        $health = $partitions->health();

        if ($health['default_rows'] > 0) {
            // A warning: the rows are billed, but the default partition is never pruned.
            $this->warn(sprintf(
                'The default partition holds %d row(s): a day went by without its partition.',
                $health['default_rows'],
            ));
        }

        $this->line(sprintf('usage_events has %d partition(s).', $health['partitions']));

        return self::SUCCESS;
    }

    private function intOption(string $name): ?int
    {
        $value = $this->option($name);

        return is_string($value) && preg_match('/^\d+$/', $value) === 1 ? (int) $value : null;
    }

    private function config(string $key, int $default): int
    {
        $value = config($key);

        return is_int($value) ? $value : $default;
    }
}
