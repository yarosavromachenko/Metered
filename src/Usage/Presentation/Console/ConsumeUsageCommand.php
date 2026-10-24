<?php

declare(strict_types=1);

namespace Metered\Usage\Presentation\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use Metered\Shared\Infrastructure\Tracing\TelemetryFlush;
use Metered\Usage\Infrastructure\Redis\ConsumeReport;
use Metered\Usage\Infrastructure\Redis\DatabaseUnavailable;
use Metered\Usage\Infrastructure\Redis\StreamConsumer;
use RedisException;
use Throwable;

/**
 * A daemon, not a queued job: batching, ack-after-commit and reclaiming rely
 * on the consumer group (ADR-0003). `SIGTERM` finishes the current batch.
 * When PostgreSQL or Redis cannot be reached it waits between passes, doubling
 * the wait up to a limit, until a pass succeeds.
 */
final class ConsumeUsageCommand extends Command
{
    protected $signature = 'usage:consume
        {--once : Make a single pass and exit, for tests and for a manual drain}
        {--max-batches= : Stop after this many passes, for a bounded run}
        {--consumer= : This consumer\'s name inside the group, defaults to host and pid}';

    protected $description = 'Read usage events from the stream and write them, with their aggregates, to PostgreSQL';

    private bool $stopping = false;

    public function handle(StreamConsumer $consumer, TelemetryFlush $telemetry): int
    {
        $consumer->ensureGroup();

        $name = $this->consumerName();
        $limit = $this->batchLimit();
        $passes = 0;
        $initialBackoff = max(1, $this->configInt('metered.usage.consumer.backoff_initial_milliseconds', 500));
        $maxBackoff = max($initialBackoff, $this->configInt('metered.usage.consumer.backoff_max_milliseconds', 30_000));
        $backoff = $initialBackoff;
        $failed = false;
        $unreachable = false;

        // The handler only sets a flag, checked between batches.
        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->stopping = true;
            $this->line('Finishing the batch in hand, then stopping.');
        });

        $this->info(sprintf('usage:consume started as "%s".', $name));

        while (! $this->stopping) {
            $passes++;

            try {
                $this->report($consumer->consumeOnce($name));
                $failed = false;
                $unreachable = false;
                $backoff = $initialBackoff;
            } catch (Throwable $failure) {
                // The failed messages stay pending.
                $this->error('Batch failed: ' . $failure->getMessage());
                report($failure);
                $failed = true;
                // One tenant's failed write is retried at once; the others' events are waiting.
                $unreachable = $failure instanceof DatabaseUnavailable || $failure instanceof RedisException;
            }

            $telemetry->flushIfDue();

            if ($this->option('once') === true || ($limit !== null && $passes >= $limit)) {
                break;
            }

            if ($unreachable) {
                Sleep::for($backoff)->milliseconds();
                $backoff = min($backoff * 2, $maxBackoff);
            }
        }

        $this->info('usage:consume stopped.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function report(ConsumeReport $report): void
    {
        if ($report->isEmpty()) {
            return;
        }

        $this->line(sprintf(
            'read %d, reclaimed %d, counted %d, duplicates %d, rejected %d, dead-lettered %d',
            $report->read,
            $report->reclaimed,
            $report->outcome->counted,
            $report->outcome->duplicates,
            $report->outcome->rejected,
            $report->deadLettered,
        ));
    }

    private function consumerName(): string
    {
        $given = $this->option('consumer');

        if (is_string($given) && trim($given) !== '') {
            return trim($given);
        }

        $host = gethostname();

        return ($host === false ? 'consumer' : $host) . '-' . getmypid();
    }

    private function configInt(string $key, int $default): int
    {
        $value = config($key);

        return is_int($value) ? $value : $default;
    }

    private function batchLimit(): ?int
    {
        $value = $this->option('max-batches');

        return is_string($value) && preg_match('/^\d+$/', $value) === 1 ? (int) $value : null;
    }
}
