<?php

declare(strict_types=1);

namespace Metered\Simulation\Presentation\Console;

use Illuminate\Console\Command;
use Metered\Simulation\Application\Seed\Profile;
use Metered\Simulation\Application\Seed\SeedTenant;
use Metered\Simulation\Application\Seed\SeedTenantHandler;

/**
 * ADR-0016. Without --key it creates the organization and prints its key once.
 */
final class SeedCommand extends Command
{
    protected $signature = 'sim:seed
        {--profile=small : small, demo or heavy}
        {--organization=Northwind Cloud : The name of the organization to create}
        {--key= : Seed the tenant this API key belongs to instead of creating one}
        {--seed=1 : Randomness seed; the same seed seeds the same data}
        {--demo : Create the organization as a demo one, which demo:reset may purge (demo mode only)}
        {--json : Print the result, key included, as one JSON object for scripts}';

    protected $description = 'Seed a tenant with catalog, customers, webhooks and usage through the API';

    public function handle(SeedTenantHandler $handler): int
    {
        $profile = Profile::tryFrom($this->text('profile'));

        if ($profile === null) {
            $this->components->error('The profile must be small, demo or heavy.');

            return self::INVALID;
        }

        $key = $this->option('key');
        $started = microtime(true);

        $report = $handler->handle(new SeedTenant(
            profile: $profile,
            seed: (int) $this->option('seed'),
            organizationName: $this->text('organization'),
            token: is_string($key) && $key !== '' ? $key : null,
            progress: $this->option('json') === true ? null : fn(string $line) => $this->line('  <fg=gray>·</> ' . $line),
            demo: $this->option('demo') === true,
        ));

        if ($this->option('json') === true) {
            $this->line(json_encode([
                'organization' => $report->organizationSlug,
                'key' => $report->token,
                'customers' => $report->customers,
                'history_events' => $report->historyEvents,
                'events_sent' => $report->eventsSent,
                'seconds' => round(microtime(true) - $started, 1),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info(sprintf('Seeded the %s profile in %.1fs.', $profile->value, microtime(true) - $started));
        $this->table(['', ''], [
            ['Organization', $report->organizationSlug ?? '(the key\'s own)'],
            ['Meters / plans', sprintf('%d / %d', $report->meters, $report->plans)],
            ['Customers', (string) $report->customers],
            ['Webhook endpoints', (string) $report->endpoints],
            ['History (bulk-loaded)', sprintf('%d events in %d hourly aggregates, reconciled', $report->historyEvents, $report->historyAggregates)],
            ['Invoices settled', sprintf('%d paid, %d voided', $report->invoicesPaid, $report->invoicesVoided)],
            ['Events sent', sprintf('%d (%d duplicates, %d meant to be rejected)', $report->eventsSent, $report->duplicatesSent, $report->rejectsSent)],
            ['Events accepted', (string) $report->eventsAccepted],
        ]);

        if ($report->token !== null) {
            $this->components->warn('The organization\'s key, shown this once:');
            $this->line($report->token);
        }

        return self::SUCCESS;
    }

    /**
     * Narrowed, not cast: the command is not registered in every environment.
     */
    private function text(string $option): string
    {
        $value = $this->option($option);

        return is_string($value) ? $value : '';
    }
}
