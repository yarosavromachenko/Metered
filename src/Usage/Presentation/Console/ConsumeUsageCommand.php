<?php

declare(strict_types=1);

namespace Metered\Usage\Presentation\Console;

use Illuminate\Console\Command;
use Metered\Shared\Infrastructure\Tracing\TelemetryFlush;
use Metered\Usage\Infrastructure\Redis\ConsumeReport;
use Metered\Usage\Infrastructure\Redis\StreamConsumer;
use Throwable;

/**
 * A daemon, not a queued job: batching, ack-after-commit and reclaiming rely
 * on the consumer group (ADR-0003). `SIGTERM` finishes the current batch.
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

        // The handler only sets a flag, checked between batches.
        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->stopping = true;
            $this->line('Finishing the batch in hand, then stopping.');
        });

        $this->info(sprintf('usage:consume started as "%s".', $name));

        while (! $this->stopping) {
            try {
                $report = $consumer->consumeOnce($name);
            } catch (Throwable $failure) {
                // Report and continue; the failed messages stay pending.
                $this->error('Batch failed: ' . $failure->getMessage());
                report($failure);

                continue;
            }

            $this->report($report);
            $telemetry->flushIfDue();

            $passes++;

            if ($this->option('once') === true || ($limit !== null && $passes >= $limit)) {
                break;
            }
        }

        $this->info('usage:consume stopped.');

        return self::SUCCESS;
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

    private function batchLimit(): ?int
    {
        $value = $this->option('max-batches');

        return is_string($value) && preg_match('/^\d+$/', $value) === 1 ? (int) $value : null;
    }
}
