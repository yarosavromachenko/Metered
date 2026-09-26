<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Outbox;

use DateInterval;
use Illuminate\Database\DatabaseManager;
use Psr\Clock\ClockInterface;

/**
 * Removes outbox messages that were published long enough ago (ADR-0005).
 *
 * A published row has done its job: the relay never reads it again, and the
 * partial index keeps the poll from seeing it. Kept for a while it answers
 * "was this event sent, and when"; kept forever it is a table that only grows.
 * An unpublished row is never removed, however old — that would drop an event
 * whose state change already committed.
 *
 * Deleted in chunks, so no single statement holds its locks for long on a
 * table the relay is polling. Each chunk starts after the last id the one
 * before it removed, so a long first run does not walk the dead rows of every
 * earlier chunk again.
 */
final readonly class OutboxPruner
{
    public function __construct(
        private DatabaseManager $db,
        private ClockInterface $clock,
        private string $connection,
        private int $chunk = 10_000,
    ) {}

    /**
     * @return int how many published messages were removed
     */
    public function prune(int $retentionDays): int
    {
        $cutoff = $this->clock->now()->sub(new DateInterval(sprintf('P%dD', $retentionDays)));
        $removed = 0;
        $after = '00000000-0000-0000-0000-000000000000';

        do {
            $rows = $this->db->connection($this->connection)->select(
                'WITH doomed AS (
                        SELECT id FROM outbox_messages
                         WHERE id > ?::uuid AND published_at IS NOT NULL AND published_at < ?
                         ORDER BY id
                         LIMIT ?
                 )
                 DELETE FROM outbox_messages o USING doomed
                  WHERE o.id = doomed.id
              RETURNING o.id::text AS id',
                [$after, $cutoff, $this->chunk],
            );

            foreach ($rows as $row) {
                $id = is_object($row) ? get_object_vars($row)['id'] ?? null : null;
                $after = is_string($id) && $id > $after ? $id : $after;
            }

            $removed += count($rows);
        } while (count($rows) === $this->chunk);

        return $removed;
    }
}
