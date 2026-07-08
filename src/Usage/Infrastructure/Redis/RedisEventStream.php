<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Redis;

use Illuminate\Redis\Connections\PhpRedisConnection;
use Metered\Usage\Application\Stream\Batch;
use Metered\Usage\Application\Stream\EventStream;
use Metered\Usage\Application\Stream\StreamDepth;
use Redis;

/**
 * The stream itself: one pipelined round trip per batch, and a backlog check
 * for the backpressure decision.
 *
 * Trimming is approximate (`MAXLEN ~`). Exact trimming makes every XADD walk
 * the stream looking for the boundary, and the entire argument for this design
 * is that XADD is cheap. The bound is a safety net against a stalled consumer
 * filling memory, not a retention policy — retention lives in PostgreSQL,
 * where the events end up.
 */
final readonly class RedisEventStream implements EventStream, StreamDepth
{
    public function __construct(
        private PhpRedisConnection $connection,
        private string $key,
        private string $group,
        private int $maxLength,
    ) {}

    public function append(Batch $batch): void
    {
        $messages = StreamEnvelope::encodeBatch($batch);

        if ($messages === []) {
            return;
        }

        $key = $this->key;
        $maxLength = $this->maxLength;

        // One round trip for the whole batch. A hundred sequential XADDs would
        // be a hundred round trips, which at a millisecond each is most of the
        // latency budget of an endpoint on somebody's hot path.
        $this->connection->pipeline(static function (Redis $pipe) use ($key, $messages, $maxLength): void {
            foreach ($messages as $fields) {
                // '*' lets Redis assign the id: an id here is a position in
                // the stream, not an identity we have an opinion about.
                $pipe->xadd($key, '*', $fields, $maxLength, true);
            }
        });
    }

    /**
     * What the consumer group has not finished with: entries it has never
     * been handed, plus entries it holds unacknowledged.
     *
     * Deliberately not `XLEN`. A stream keeps an entry after it has been read
     * and acknowledged — only `MAXLEN` trimming removes it — so the length is
     * mostly finished work. Backpressure on the length would start shedding
     * load once the bound was half full and keep shedding until trimming
     * caught up, with an idle consumer and an empty backlog, which is the
     * opposite of what shedding is for. The panel's widget reads the same
     * number and would have said "waiting in the stream" about work already
     * written to PostgreSQL.
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

            // Redis cannot always compute the lag: trimming that removes
            // entries the group had not reached leaves it unable to say how
            // many those were, and reports null. The length is then the only
            // number available, and erring towards shedding is the safe
            // direction for a signal whose job is to protect the consumer.
            if (! is_int($undelivered)) {
                return $this->length();
            }

            return $undelivered + $unacknowledged;
        }

        // No group means no consumer has ever read from this stream, so
        // nothing in it has been dealt with and its length is exactly the
        // backlog.
        return $this->length();
    }

    private function length(): int
    {
        $length = $this->connection->command('xlen', [$this->key]);

        return is_int($length) ? $length : 0;
    }
}
