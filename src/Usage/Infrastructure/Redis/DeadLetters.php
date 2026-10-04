<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Redis;

use Illuminate\Redis\Connections\PhpRedisConnection;
use Redis;

/**
 * Written by `StreamConsumer` (original message plus three fields). Replay
 * strips those fields and re-adds the message to the ingestion stream; safe
 * to repeat thanks to deduplication.
 */
final readonly class DeadLetters
{
    public const string REASON = '_reason';

    public const string DELIVERIES = '_deliveries';

    public const string DEAD_LETTERED_AT = '_dead_lettered_at';

    public const string MALFORMED = 'malformed';

    public const string TOO_MANY_DELIVERIES = 'too_many_deliveries';

    public const string PROJECT_GONE = 'project_gone';

    public function __construct(
        private PhpRedisConnection $connection,
        private string $streamKey,
        private string $deadLetterKey,
        private int $maxLength,
    ) {}

    /**
     * @return list<DeadLetter>  newest first
     */
    public function list(int $limit): array
    {
        $entries = $this->connection->command('xrevrange', [$this->deadLetterKey, '+', '-', $limit]);
        $letters = [];

        foreach ($this->entries($entries) as $id => $fields) {
            $letters[] = DeadLetter::fromEntry($id, $fields);
        }

        return $letters;
    }

    /**
     * @return list<string>  every id in the stream, oldest first
     */
    public function ids(): array
    {
        $entries = $this->connection->command('xrange', [$this->deadLetterKey, '-', '+']);

        return array_map(strval(...), array_keys($this->entries($entries)));
    }

    public function replay(string $id): ReplayOutcome
    {
        $found = $this->entries($this->connection->command('xrange', [$this->deadLetterKey, $id, $id]));
        $fields = $found[$id] ?? null;

        if ($fields === null) {
            return ReplayOutcome::NotFound;
        }

        $reason = $fields[self::REASON] ?? null;

        if ($reason === self::MALFORMED) {
            return ReplayOutcome::Malformed;
        }

        if ($reason === self::PROJECT_GONE) {
            return ReplayOutcome::ProjectGone;
        }

        $message = array_filter(
            $fields,
            static fn(string $name): bool => ! str_starts_with($name, '_'),
            ARRAY_FILTER_USE_KEY,
        );

        $streamKey = $this->streamKey;
        $deadLetterKey = $this->deadLetterKey;
        $maxLength = $this->maxLength;

        // Atomic: add and delete together.
        $this->connection->transaction(static function (Redis $transaction) use ($streamKey, $deadLetterKey, $maxLength, $id, $message): void {
            $transaction->xadd($streamKey, '*', $message, $maxLength, true);
            $transaction->xdel($deadLetterKey, [$id]);
        });

        return ReplayOutcome::Replayed;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function entries(mixed $entries): array
    {
        $result = [];

        foreach (is_array($entries) ? $entries : [] as $id => $fields) {
            $message = [];

            foreach (is_array($fields) ? $fields : [] as $name => $value) {
                $message[(string) $name] = is_scalar($value) ? (string) $value : '';
            }

            $result[(string) $id] = $message;
        }

        return $result;
    }
}
