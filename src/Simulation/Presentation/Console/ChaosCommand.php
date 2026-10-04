<?php

declare(strict_types=1);

namespace Metered\Simulation\Presentation\Console;

use Illuminate\Console\Command;
use Metered\Simulation\Application\Chaos\Check;
use Metered\Simulation\Application\Chaos\RunChaos;
use Metered\Simulation\Application\Chaos\RunChaosHandler;
use Metered\Simulation\Application\Chaos\Scenario;

/**
 * Exits non-zero when an invariant fails.
 */
final class ChaosCommand extends Command
{
    protected $signature = 'sim:chaos
        {scenario : kill-consumer, kill-redis-brief, kill-relay, slow-webhook or failing-webhook}
        {--events=2000 : Usage events sent, in the usage scenarios}';

    protected $description = 'Break one part of the running stack and check that nothing was lost';

    public function handle(RunChaosHandler $handler): int
    {
        // Narrowed, not cast: the command is not registered in every environment.
        $name = $this->argument('scenario');
        $scenario = is_string($name) ? Scenario::tryFrom($name) : null;

        if ($scenario === null) {
            $this->components->error('The scenario must be one of: ' . implode(', ', array_map(static fn(Scenario $s): string => $s->value, Scenario::cases())) . '.');

            return self::INVALID;
        }

        $this->components->info($scenario->describe());

        $report = $handler->handle(new RunChaos(
            scenario: $scenario,
            events: max(100, (int) $this->option('events')),
            progress: fn(string $line) => $this->line('  <fg=gray>·</> ' . $line),
        ));

        $this->newLine();

        foreach ($report->checks as $check) {
            $this->line(sprintf('  %s %s <fg=gray>— %s</>', $check->held ? '<fg=green>✓</>' : '<fg=red>✗</>', $check->invariant, $check->detail));
        }

        foreach ($report->notes as $note) {
            $this->line('  <fg=gray>' . $note . '</>');
        }

        $this->newLine();
        $held = count(array_filter($report->checks, static fn(Check $check): bool => $check->held));

        if (! $report->held()) {
            $this->components->error(sprintf('%d of %d invariants held.', $held, count($report->checks)));

            return self::FAILURE;
        }

        $this->components->info(sprintf('All %d invariants held.', $held));

        return self::SUCCESS;
    }
}
