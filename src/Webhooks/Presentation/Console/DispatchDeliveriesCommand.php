<?php

declare(strict_types=1);

namespace Metered\Webhooks\Presentation\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;
use Metered\Shared\Infrastructure\Tracing\Tracing;
use Metered\Webhooks\Domain\Delivery\DeliveryRepository;
use Metered\Webhooks\Infrastructure\Persistence\DeliveryTraceContexts;
use Metered\Webhooks\Infrastructure\Queue\AttemptDeliveryJob;
use Psr\Clock\ClockInterface;

/**
 * Queues an attempt for every delivery that is due: new ones and retries
 * alike. The database says what is due; the queue only carries it.
 *
 * Each attempt is queued inside the trace its delivery was created in, so the
 * outgoing request lands in the same trace as the event that caused it, on the
 * first attempt and on every retry (ADR-0012).
 */
final class DispatchDeliveriesCommand extends Command
{
    protected $signature = 'webhooks:dispatch {--limit=500 : How many due deliveries to queue in one pass}';

    protected $description = 'Queue an attempt for every webhook delivery that is due';

    public function handle(
        DeliveryRepository $deliveries,
        DeliveryTraceContexts $traces,
        Tracing $tracing,
        Dispatcher $bus,
        ClockInterface $clock,
        Repository $config,
    ): int {
        $limit = $this->option('limit');
        $queue = $config->get('metered.webhooks.queue');
        $queued = 0;

        $due = $deliveries->dueAt($clock->now(), is_numeric($limit) ? max(1, (int) $limit) : 500);
        $contexts = $traces->of($due);

        foreach ($due as $delivery) {
            // The queue writes the active context into the job's payload.
            $scope = $tracing->extract($contexts[$delivery->id->value] ?? [])->activate();

            try {
                $bus->dispatch(new AttemptDeliveryJob(
                    $delivery->tenant->organizationId->value,
                    $delivery->tenant->projectId->value,
                    $delivery->id->value,
                )->onQueue(is_string($queue) ? $queue : 'webhooks'));
            } finally {
                $scope->detach();
            }

            ++$queued;
        }

        $this->components->info(sprintf('Queued %d delivery attempt(s).', $queued));

        return self::SUCCESS;
    }
}
