<?php

declare(strict_types=1);

namespace Metered\Usage\Presentation\Console;

use Illuminate\Console\Command;
use Metered\Usage\Infrastructure\Redis\DeadLetters;
use Metered\Usage\Infrastructure\Redis\ReplayOutcome;

/**
 * By id or `--all`. Malformed messages and deleted projects are refused.
 * Exits non-zero if any id was refused or not found.
 */
final class ReplayDeadLettersCommand extends Command
{
    protected $signature = 'usage:dead-letters:replay
        {ids?* : The ids to replay, as usage:dead-letters shows them}
        {--all : Replay every entry in the dead-letter stream}';

    protected $description = 'Put dead-lettered usage messages back on the ingestion stream';

    public function handle(DeadLetters $deadLetters): int
    {
        $ids = $this->argument('ids');
        $ids = is_array($ids) ? array_values(array_filter($ids, is_string(...))) : [];
        $all = $this->option('all') === true;

        if ($all === ($ids !== [])) {
            $this->error('Name the ids to replay, or pass --all — one of the two.');

            return self::FAILURE;
        }

        if ($all) {
            $ids = $deadLetters->ids();
        }

        $counts = ['replayed' => 0, 'not_found' => 0, 'malformed' => 0, 'project_gone' => 0];

        foreach ($ids as $id) {
            $outcome = $deadLetters->replay($id);
            $counts[$outcome->value]++;

            match ($outcome) {
                ReplayOutcome::Replayed => $this->line(sprintf('%s  replayed', $id)),
                ReplayOutcome::NotFound => $this->warn(sprintf('%s  not found — never there, or already replayed', $id)),
                ReplayOutcome::Malformed => $this->warn(sprintf('%s  refused — malformed, the consumer would set it aside again', $id)),
                ReplayOutcome::ProjectGone => $this->warn(sprintf('%s  refused — its project was deleted', $id)),
            };
        }

        $this->info(sprintf(
            '%d replayed, %d not found, %d refused as malformed, %d refused because the project is gone.',
            $counts['replayed'],
            $counts['not_found'],
            $counts['malformed'],
            $counts['project_gone'],
        ));

        return $counts['replayed'] === count($ids) ? self::SUCCESS : self::FAILURE;
    }
}
