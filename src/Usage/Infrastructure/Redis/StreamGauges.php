<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Redis;

use Illuminate\Redis\Connections\PhpRedisConnection;
use Metered\Shared\Application\Metrics\GaugeReading;
use Metered\Shared\Application\Metrics\GaugeSource;
use Metered\Usage\Application\Metrics\UsageMetrics;
use Metered\Usage\Application\Stream\StreamDepth;

/**
 * Backlog, stream length, dead letters, and memory of the ingestion Redis
 * (ADR-0002).
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
        // INFO, not CONFIG GET, which managed Redis often disables.
        $memory = $this->connection->command('info', ['memory']);
        $memory = is_array($memory) ? $memory : [];

        return [
            new GaugeReading(UsageMetrics::streamPending(), $this->depth->pending()),
            new GaugeReading(UsageMetrics::streamLength(), $this->length($this->key)),
            new GaugeReading(UsageMetrics::deadLetters(), $this->length($this->deadLetterKey)),
            new GaugeReading(UsageMetrics::redisMemoryUsed(), $this->bytes($memory, 'used_memory')),
            new GaugeReading(UsageMetrics::redisMemoryLimit(), $this->bytes($memory, 'maxmemory')),
        ];
    }

    private function length(string $key): int
    {
        $length = $this->connection->command('xlen', [$key]);

        return is_int($length) ? $length : 0;
    }

    /**
     * @param  array<array-key, mixed>  $memory
     */
    private function bytes(array $memory, string $field): int
    {
        $value = $memory[$field] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }
}
