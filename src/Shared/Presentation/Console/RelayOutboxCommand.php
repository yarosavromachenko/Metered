<?php

declare(strict_types=1);

namespace Metered\Shared\Presentation\Console;

use Illuminate\Console\Command;
use Metered\Shared\Infrastructure\Outbox\OutboxRelay;
use Metered\Shared\Infrastructure\Tracing\TelemetryFlush;

/**
 * A daemon, not a scheduled command: the scheduler's one-minute granularity
 * would be added to every event's latency.
 */
final class RelayOutboxCommand extends Command
{
    protected $signature = 'outbox:relay
        {--once : Publish a single batch and exit, instead of running as a daemon}
        {--batch= : How many messages to claim per pass}';

    protected $description = 'Publish committed outbox messages to the queue';

    private bool $shouldStop = false;

    public function handle(OutboxRelay $relay, TelemetryFlush $telemetry): int
    {
        $batch = $this->batchSize();

        $this->listenForShutdown();

        do {
            $published = $relay->relayBatch($batch);
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
        } while (! $this->shouldStop);

        $this->components->info('Stopped.');

        return self::SUCCESS;
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
