<?php

declare(strict_types=1);

namespace Metered\Simulation\Presentation\Console;

use Illuminate\Console\Command;
use Metered\Simulation\Application\Traffic\GenerateTraffic;
use Metered\Simulation\Application\Traffic\GenerateTrafficHandler;

final class TrafficCommand extends Command
{
    protected $signature = 'sim:traffic
        {--key= : An API key of the tenant to send usage for (usage:write and admin)}
        {--rps=50 : Events per second}
        {--duration=60 : Seconds to run for}
        {--dup-rate=0.01 : Share of events sent again}
        {--late-rate=0.02 : Share of events reported up to three days late}
        {--out-of-order : Move every event up to ten minutes back}
        {--burst : Five times the rate for one second in fifteen}
        {--seed=1 : Randomness seed}';

    protected $description = 'Send live usage to a tenant through the ingestion API';

    public function handle(GenerateTrafficHandler $handler): int
    {
        $key = $this->option('key');

        if (! is_string($key) || $key === '') {
            $this->components->error('Name the tenant with --key: the key sim:seed printed, or one issued in the panel.');

            return self::INVALID;
        }

        $report = $handler->handle(new GenerateTraffic(
            token: $key,
            eventsPerSecond: max(1, (int) $this->option('rps')),
            seconds: max(1, (int) $this->option('duration')),
            duplicateRate: (float) $this->option('dup-rate'),
            lateRate: (float) $this->option('late-rate'),
            outOfOrder: $this->option('out-of-order') === true,
            burst: $this->option('burst') === true,
            seed: (int) $this->option('seed'),
            progress: function (int $second, int $sent): void {
                if ($second % 10 === 0) {
                    $this->line(sprintf('  <fg=gray>·</> %ds, %d events sent', $second, $sent));
                }
            },
        ));

        $this->components->info(sprintf(
            'Sent %d events in %d requests: %d accepted, %d of them duplicates, %d late.',
            $report->sent,
            $report->requests,
            $report->accepted,
            $report->duplicates,
            $report->late,
        ));

        return self::SUCCESS;
    }
}
