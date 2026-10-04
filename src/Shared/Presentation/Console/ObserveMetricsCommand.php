<?php

declare(strict_types=1);

namespace Metered\Shared\Presentation\Console;

use Illuminate\Console\Command;
use Metered\Shared\Infrastructure\Metrics\GaugeObserver;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface as ExportingMeterProvider;

/**
 * The single process that reads and exports gauges (ADR-0019).
 */
final class ObserveMetricsCommand extends Command
{
    protected $signature = 'metrics:observe
        {--once : Read and export once, then exit}';

    protected $description = 'Read the gauges and export them, on a timer';

    private bool $stopping = false;

    public function handle(GaugeObserver $observer, MeterProviderInterface $meterProvider): int
    {
        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->stopping = true;
        });

        $interval = $this->interval();

        do {
            $taken = $observer->observe();

            if ($meterProvider instanceof ExportingMeterProvider) {
                $meterProvider->forceFlush();
            }

            if ($this->option('once') === true) {
                $this->components->info(sprintf('Observed %d reading(s).', $taken));

                return self::SUCCESS;
            }

            // Short sleeps so a stop signal is handled quickly.
            for ($slept = 0; $slept < $interval && ! $this->stopping; ++$slept) {
                sleep(1);
            }
        } while (! $this->stopping);

        return self::SUCCESS;
    }

    private function interval(): int
    {
        $seconds = config('metered.metrics.observe_interval_seconds');

        return is_int($seconds) && $seconds > 0 ? $seconds : 15;
    }
}
