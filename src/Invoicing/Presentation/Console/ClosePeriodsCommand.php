<?php

declare(strict_types=1);

namespace Metered\Invoicing\Presentation\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;
use Metered\Billing\Application\Contract\SubscriptionBilling;
use Metered\Invoicing\Domain\Invoice\InvoiceRepository;
use Metered\Invoicing\Infrastructure\Queue\CloseSubscriptionPeriodsJob;
use Psr\Clock\ClockInterface;

/**
 * Finds the subscriptions with a period past its grace window and queues one
 * close per subscription on the billing queue.
 *
 * Deciding "due" here keeps the queue to the work there is; the job decides
 * again, and anything this gets wrong in either direction costs a no-op job
 * or a five-minute wait, never an invoice.
 */
final class ClosePeriodsCommand extends Command
{
    protected $signature = 'billing:close-periods';

    protected $description = 'Queue a period close for every subscription with a period past its grace window';

    public function handle(
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

            $bus->dispatch(new CloseSubscriptionPeriodsJob(
                $subscription->tenant->organizationId->value,
                $subscription->tenant->projectId->value,
                $subscription->id->value,
            )->onQueue(is_string($queue) ? $queue : 'billing'));

            ++$queued;
        }

        $this->components->info(sprintf('Queued %d period close(s).', $queued));

        return self::SUCCESS;
    }

    private function seconds(Repository $config, string $key, int $default): int
    {
        $value = $config->get($key);

        return is_int($value) ? $value : $default;
    }
}
