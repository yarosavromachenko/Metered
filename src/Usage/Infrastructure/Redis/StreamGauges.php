<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Redis;

use Illuminate\Redis\Connections\PhpRedisConnection;
use Metered\Shared\Application\Metrics\GaugeReading;
use Metered\Shared\Application\Metrics\GaugeSource;
use Metered\Usage\Application\Metrics\UsageMetrics;
use Metered\Usage\Application\Stream\StreamDepth;

/**
 * The ingestion stream as an operator watches it: how much is waiting (what
 * backpressure decides on), how long the stream is, and what was set aside.
 */
final readonly class StreamGauges implements GaugeSource
{
    public function __construct(
        private PhpRedisConnection $connection,
        private StreamDepth $depth,
        private string $key,
        private string $deadLetterKey,
    ) {}

    public function read(): array
    {
        return [
            new GaugeReading(UsageMetrics::streamPending(), $this->depth->pending()),
            new GaugeReading(UsageMetrics::streamLength(), $this->length($this->key)),
            new GaugeReading(UsageMetrics::deadLetters(), $this->length($this->deadLetterKey)),
        ];
    }

    private function length(string $key): int
    {
        $length = $this->connection->command('xlen', [$key]);

        return is_int($length) ? $length : 0;
    }
}
