<?php

declare(strict_types=1);

namespace Metered\Usage\Presentation\Console;

use Illuminate\Console\Command;
use Metered\Shared\Infrastructure\Tracing\TelemetryFlush;
use Metered\Usage\Infrastructure\Redis\ConsumeReport;
use Metered\Usage\Infrastructure\Redis\StreamConsumer;
use Throwable;

/**
 * `usage:consume` — the daemon that moves events from the stream into
 * PostgreSQL.
 *
 * A long-running process rather than a queued job, because everything that
 * makes this correct lives in the consumer group: batching, acknowledging
 * after the commit, reclaiming what a dead worker left behind. Horizon would
 * supervise it for free and take all three away (ADR-0003).
 *
 * `SIGTERM` finishes the batch in hand and exits. A container being replaced
 * gets a clean handover instead of a batch of five hundred events whose
 * acknowledgement never happened.
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

        // Laravel's own trap: the signal sets a flag, and the flag is read
        // between batches. Doing anything more inside a signal handler is how
        // a shutdown corrupts the work it interrupted.
        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->stopping = true;
            $this->line('Finishing the batch in hand, then stopping.');
        });

        $this->info(sprintf('usage:consume started as "%s".', $name));

        while (! $this->stopping) {
            try {
                $report = $consumer->consumeOnce($name);
            } catch (Throwable $failure) {
                // The failed tenant's messages were not acknowledged, so
                // nothing is lost: they will be reclaimed and tried again. The
                // other tenants of the read were written and acknowledged
                // before this was thrown. Reporting and continuing beats
                // exiting, which would turn one bad batch into an outage.
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
