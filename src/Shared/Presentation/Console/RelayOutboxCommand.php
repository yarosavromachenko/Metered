<?php

declare(strict_types=1);

namespace Metered\Shared\Presentation\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use Metered\Shared\Infrastructure\Outbox\OutboxRelay;
use Metered\Shared\Infrastructure\Persistence\DatabaseOutage;
use Metered\Shared\Infrastructure\Tracing\TelemetryFlush;
use Throwable;

/**
 * A daemon, not a scheduled command: the scheduler's one-minute granularity
 * would be added to every event's latency. When PostgreSQL cannot be reached
 * it waits between passes, doubling the wait up to a limit, until a pass
 * succeeds; any other failure ends the process.
 */
final class RelayOutboxCommand extends Command
{
    protected $signature = 'outbox:relay
        {--once : Publish a single batch and exit, instead of running as a daemon}
        {--max-batches= : Stop after this many passes, for a bounded run}
        {--batch= : How many messages to claim per pass}';

    protected $description = 'Publish committed outbox messages to the queue';

    private bool $shouldStop = false;

    public function handle(OutboxRelay $relay, TelemetryFlush $telemetry, DatabaseOutage $outage): int
    {
        $batch = $this->batchSize();
        $limit = $this->passLimit();
        $passes = 0;
        $initialBackoff = max(1, $this->configInt('metered.outbox.backoff_initial_milliseconds', 500));
        $maxBackoff = max($initialBackoff, $this->configInt('metered.outbox.backoff_max_milliseconds', 30_000));
        $backoff = $initialBackoff;

        $this->listenForShutdown();

        do {
            $passes++;

            try {
                $published = $relay->relayBatch($batch);
                $backoff = $initialBackoff;
            } catch (Throwable $failure) {
                if ($this->option('once') === true || ! $outage->causedBy($failure)) {
                    throw $failure;
                }

                if ($limit !== null && $passes >= $limit) {
                    break;
                }

                $this->components->warn(sprintf('PostgreSQL is unreachable, retrying in %d ms.', $backoff));
                Sleep::for($backoff)->milliseconds();
                $backoff = min($backoff * 2, $maxBackoff);

                continue;
            }

            $telemetry->flushIfDue();

            if ($published > 0) {
                $this->components->info(sprintf('Published %d message(s).', $published));
            }

            if ($this->option('once') === true) {
                return self::SUCCESS;
            }

            // A full batch means more is waiting: no sleep.
            if ($published === 0) {
                usleep($this->idleMicroseconds());
            }
        } while (! $this->shouldStop && ($limit === null || $passes < $limit));

        $this->components->info('Stopped.');

        return self::SUCCESS;
    }

    private function passLimit(): ?int
    {
        $option = $this->option('max-batches');

        return is_string($option) && preg_match('/^\d+$/', $option) === 1 ? (int) $option : null;
    }

    private function batchSize(): int
    {
        $option = $this->option('batch');

        if (is_string($option) && preg_match('/^\d+$/', $option) === 1) {
            return (int) $option;
        }

        return $this->configInt('metered.outbox.batch_size', 100);
    }

    private function idleMicroseconds(): int
    {
        $seconds = config('metered.outbox.idle_sleep_seconds');

        $seconds = is_numeric($seconds) ? (float) $seconds : 0.5;

        return (int) round($seconds * 1_000_000);
    }

    private function configInt(string $key, int $default): int
    {
        $value = config($key);

        return is_int($value) ? $value : $default;
    }

    /**
     * Finishes the current batch, then exits.
     */
    private function listenForShutdown(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->shouldStop = true;
            });
        }
    }
}
