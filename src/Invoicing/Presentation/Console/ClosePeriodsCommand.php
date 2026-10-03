<?php

declare(strict_types=1);

namespace Metered\Invoicing\Presentation\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;
use Metered\Billing\Application\Contract\SubscriptionBilling;
use Metered\Invoicing\Domain\Invoice\InvoiceRepository;
use Metered\Invoicing\Infrastructure\Queue\CloseSubscriptionPeriodsJob;
use Metered\Shared\Infrastructure\Tracing\Tracing;
use Psr\Clock\ClockInterface;

/**
 * Queues one close job per subscription with a due period; the job checks
 * again. Each run starts a trace that continues into the jobs, the outbox and
 * webhooks (ADR-0012).
 */
final class ClosePeriodsCommand extends Command
{
    protected $signature = 'billing:close-periods
        {--sync : Close them in this process, one after another, instead of queueing}';

    protected $description = 'Queue a period close for every subscription with a period past its grace window';

    public function handle(
        SubscriptionBilling $billing,
        InvoiceRepository $invoices,
        Dispatcher $bus,
        ClockInterface $clock,
        Repository $config,
        Tracing $tracing,
    ): int {
        $span = $tracing->tracer()->spanBuilder('billing close-periods')->startSpan();
        $scope = $span->activate();

        try {
            $queued = $this->closeDue($billing, $invoices, $bus, $clock, $config);
        } finally {
            $scope->detach();
        }

        $span->setAttribute('metered.close.subscriptions', $queued);
        $span->end();

        $this->components->info(sprintf($this->option('sync') === true ? 'Closed periods for %d subscription(s).' : 'Queued %d period close(s).', $queued));

        return self::SUCCESS;
    }

    /**
     * @return int how many subscriptions had a close queued, or run
     */
    private function closeDue(
        SubscriptionBilling $billing,
        InvoiceRepository $invoices,
        Dispatcher $bus,
        ClockInterface $clock,
        Repository $config,
    ): int {
        $now = $clock->now();
        $closable = $now->modify(sprintf('-%d seconds', $this->seconds($config, 'metered.invoicing.grace_seconds', 3600)));
        $endedAfter = $now->modify(sprintf('-%d seconds', $this->seconds($config, 'metered.invoicing.ended_lookback_seconds', 2_678_400)));
        $queue = $config->get('metered.invoicing.queue');
        $queued = 0;

        foreach ($billing->billable($endedAfter) as $subscription) {
            $from = $invoices->latestFor($subscription->tenant, $subscription->id)?->period->end ?? $subscription->anchorAt;

            if ($billing->periodsEndedBy($subscription->tenant, $subscription->id, $from, $closable) === []) {
                continue;
            }

            $job = new CloseSubscriptionPeriodsJob(
                $subscription->tenant->organizationId->value,
                $subscription->tenant->projectId->value,
                $subscription->id->value,
            )->onQueue(is_string($queue) ? $queue : 'billing');

            // --sync runs the same job inline (used by the demo seed).
            $this->option('sync') === true ? $bus->dispatchSync($job) : $bus->dispatch($job);

            ++$queued;
        }

        return $queued;
    }

    private function seconds(Repository $config, string $key, int $default): int
    {
        $value = $config->get($key);

        return is_int($value) ? $value : $default;
    }
}
