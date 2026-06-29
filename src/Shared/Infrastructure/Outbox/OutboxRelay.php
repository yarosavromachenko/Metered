<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Outbox;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Metered\Shared\Application\Outbox\OutboxPublisher;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Outbox\OutboxMessage;
use Metered\Shared\Infrastructure\Persistence\RowReader;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Publishes committed outbox messages, exactly as often as their state change
 * committed and at least once each.
 *
 * Claiming uses SELECT ... FOR UPDATE SKIP LOCKED, which is what allows several
 * relays to run at the same time without coordination: each skips the rows
 * another has locked instead of waiting behind them.
 *
 * Publication happens inside the claiming transaction. A relay that publishes
 * and then dies before recording it will publish that message again later —
 * delivery is at-least-once by design, and consumers are made idempotent by the
 * inbox rather than by hoping this never happens.
 */
final readonly class OutboxRelay
{
    public function __construct(
        private DatabaseManager $db,
        private OutboxPublisher $publisher,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private string $connection,
        private int $maxAttempts,
    ) {}

    /**
     * @return int the number of messages published in this pass
     */
    public function relayBatch(int $limit): int
    {
        $connection = $this->db->connection($this->connection);

        /** @var int $published */
        $published = $connection->transaction(
            fn(ConnectionInterface $tx): int => $this->publishClaimed($tx, $limit),
        );

        return $published;
    }

    private function publishClaimed(ConnectionInterface $tx, int $limit): int
    {
        $rows = $tx->select(
            'SELECT id, aggregate_type, aggregate_id, type, payload, headers,
                    occurred_at, attempts
               FROM outbox_messages
              WHERE published_at IS NULL
                AND attempts < ?
              ORDER BY occurred_at
              LIMIT ?
                FOR UPDATE SKIP LOCKED',
            [$this->maxAttempts, $limit],
        );

        $published = 0;

        foreach ($rows as $row) {
            if (! is_object($row)) {
                continue;
            }

            $message = $this->toMessage($row);

            try {
                $this->publisher->publish($message);
            } catch (Throwable $e) {
                $this->recordFailure($tx, $message, $e);

                continue;
            }

            $this->recordPublication($tx, $message);
            $published++;
        }

        return $published;
    }

    private function recordPublication(ConnectionInterface $tx, OutboxMessage $message): void
    {
        $tx->table('outbox_messages')
            ->where('id', $message->id->value)
            ->update([
                'published_at' => $this->clock->now(),
                'attempts' => $message->attempts + 1,
                'last_attempted_at' => $this->clock->now(),
                'last_error' => null,
            ]);
    }

    private function recordFailure(ConnectionInterface $tx, OutboxMessage $message, Throwable $e): void
    {
        $attempts = $message->attempts + 1;

        $tx->table('outbox_messages')
            ->where('id', $message->id->value)
            ->update([
                'attempts' => $attempts,
                'last_attempted_at' => $this->clock->now(),
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
            ]);

        $this->logger->error('Failed to publish an outbox message.', [
            'outbox_message_id' => $message->id->value,
            'type' => $message->type,
            'attempts' => $attempts,
            'exhausted' => $attempts >= $this->maxAttempts,
            'exception' => $e->getMessage(),
        ]);
    }

    private function toMessage(object $row): OutboxMessage
    {
        $values = get_object_vars($row);

        return new OutboxMessage(
            id: Uuid::fromString(RowReader::string($values['id'] ?? null, 'id')),
            aggregateType: RowReader::string($values['aggregate_type'] ?? null, 'aggregate_type'),
            aggregateId: Uuid::fromString(RowReader::string($values['aggregate_id'] ?? null, 'aggregate_id')),
            type: RowReader::string($values['type'] ?? null, 'type'),
            payload: RowReader::jsonObject($values['payload'] ?? null, 'payload'),
            headers: RowReader::jsonStringMap($values['headers'] ?? null, 'headers'),
            occurredAt: new DateTimeImmutable(RowReader::string($values['occurred_at'] ?? null, 'occurred_at')),
            attempts: RowReader::int($values['attempts'] ?? null, 'attempts'),
        );
    }
}
