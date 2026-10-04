<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Redis;

use Illuminate\Redis\Connections\PhpRedisConnection;
use Metered\Shared\Infrastructure\Tracing\Tracing;
use Metered\Usage\Application\Stream\Batch;
use Metered\Usage\Application\Stream\EventStream;
use Metered\Usage\Application\Stream\StreamDepth;
use Metered\Usage\Application\Stream\StreamFull;
use Redis;
use RedisException;

/**
 * One pipelined round trip per batch. Approximate trimming (`MAXLEN ~`) keeps
 * XADD cheap; the bound only protects memory from a stalled consumer.
 */
final readonly class RedisEventStream implements EventStream, StreamDepth
{
    public function __construct(
        private PhpRedisConnection $connection,
        private string $key,
        private string $group,
        private int $maxLength,
        private Tracing $tracing,
    ) {}

    public function append(Batch $batch): void
    {
        // Trace context on every message.
        $messages = StreamEnvelope::encodeBatch($batch, $this->tracing->carrier());

        if ($messages === []) {
            return;
        }

        $key = $this->key;
        $maxLength = $this->maxLength;

        try {
            $this->connection->pipeline(static function (Redis $pipe) use ($key, $messages, $maxLength): void {
                foreach ($messages as $fields) {
                    $pipe->xadd($key, '*', $fields, $maxLength, true);
                }
            });
        } catch (RedisException $refused) {
            // OOM under `noeviction` means the stream is full.
            if (str_starts_with($refused->getMessage(), 'OOM ')) {
                throw StreamFull::because($refused);
            }

            throw $refused;
        }
    }

    /**
     * Group lag plus pending entries. Not `XLEN`: acknowledged entries stay
     * in the stream until trimmed.
     */
    public function pending(): int
    {
        $groups = $this->connection->command('xinfo', ['GROUPS', $this->key]);

        if (! is_array($groups)) {
            return $this->length();
        }

        foreach ($groups as $group) {
            if (! is_array($group) || ($group['name'] ?? null) !== $this->group) {
                continue;
            }

            $unacknowledged = is_int($group['pending'] ?? null) ? $group['pending'] : 0;
            $undelivered = $group['lag'] ?? null;

            // Lag is null after trimming unread entries; fall back to the length.
            if (! is_int($undelivered)) {
                return $this->length();
            }

            return $undelivered + $unacknowledged;
        }

        // No group yet: everything is backlog.
        return $this->length();
    }

    private function length(): int
    {
        $length = $this->connection->command('xlen', [$this->key]);

        return is_int($length) ? $length : 0;
    }
}
