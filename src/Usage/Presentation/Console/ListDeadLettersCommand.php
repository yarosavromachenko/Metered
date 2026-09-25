<?php

declare(strict_types=1);

namespace Metered\Usage\Presentation\Console;

use Illuminate\Console\Command;
use Metered\Usage\Infrastructure\Redis\DeadLetter;
use Metered\Usage\Infrastructure\Redis\DeadLetters;

/**
 * `usage:dead-letters` — what the consumer set aside, newest first.
 *
 * Read this before replaying: `malformed` means the message itself is wrong
 * and replaying cannot help; `too_many_deliveries` means writing it kept
 * failing, and it is worth replaying once the cause is fixed.
 */
final class ListDeadLettersCommand extends Command
{
    protected $signature = 'usage:dead-letters
        {--limit=20 : How many entries to show}';

    protected $description = 'List the usage messages the consumer set aside, newest first';

    public function handle(DeadLetters $deadLetters): int
    {
        $limit = $this->option('limit');

        if (! is_string($limit) || ! ctype_digit($limit) || (int) $limit < 1) {
            $this->error('--limit must be a positive whole number.');

            return self::FAILURE;
        }

        $letters = $deadLetters->list((int) $limit);

        if ($letters === []) {
            $this->info('The dead-letter stream is empty.');

            return self::SUCCESS;
        }

        $this->table(
            ['Id', 'Reason', 'Deliveries', 'Dead-lettered at', 'Project', 'Event', 'Meter', 'Customer'],
            array_map(static fn(DeadLetter $letter): array => [
                $letter->id,
                $letter->reason,
                $letter->deliveries ?? '—',
                $letter->deadLetteredAt ?? '—',
                $letter->projectId ?? '—',
                $letter->eventId ?? '—',
                $letter->meterCode ?? '—',
                $letter->customerReference ?? '—',
            ], $letters),
        );

        return self::SUCCESS;
    }
}
