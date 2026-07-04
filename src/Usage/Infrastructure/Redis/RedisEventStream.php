<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Redis;

use Illuminate\Redis\Connections\PhpRedisConnection;
use Metered\Usage\Application\Stream\Batch;
use Metered\Usage\Application\Stream\EventStream;
use Metered\Usage\Application\Stream\StreamDepth;
use Redis;

/**
 * The stream itself: one pipelined round trip per batch, and a length check
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

    public function pending(): int
    {
        $length = $this->connection->command('xlen', [$this->key]);

        return is_int($length) ? $length : 0;
    }
}
