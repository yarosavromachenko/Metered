<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Outbox;

use DateInterval;
use Illuminate\Database\DatabaseManager;
use Psr\Clock\ClockInterface;

/**
 * Deletes published messages past retention (ADR-0005); unpublished ones are
 * never deleted. Works in chunks, each starting after the last deleted id.
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
