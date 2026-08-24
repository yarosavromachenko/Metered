<?php

declare(strict_types=1);

namespace Metered\Webhooks\Presentation\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;
use Metered\Webhooks\Domain\Delivery\DeliveryRepository;
use Metered\Webhooks\Infrastructure\Queue\AttemptDeliveryJob;
use Psr\Clock\ClockInterface;

/**
 * Queues an attempt for every delivery that is due: new ones and retries
 * alike. The database says what is due; the queue only carries it.
 */
final class DispatchDeliveriesCommand extends Command
{
    protected $signature = 'webhooks:dispatch {--limit=500 : How many due deliveries to queue in one pass}';

    protected $description = 'Queue an attempt for every webhook delivery that is due';

    public function handle(DeliveryRepository $deliveries, Dispatcher $bus, ClockInterface $clock, Repository $config): int
    {
        $limit = $this->option('limit');
        $queue = $config->get('metered.webhooks.queue');
        $queued = 0;

        foreach ($deliveries->dueAt($clock->now(), is_numeric($limit) ? max(1, (int) $limit) : 500) as $delivery) {
            $bus->dispatch(new AttemptDeliveryJob(
                $delivery->tenant->organizationId->value,
                $delivery->tenant->projectId->value,
                $delivery->id->value,
            )->onQueue(is_string($queue) ? $queue : 'webhooks'));

            ++$queued;
        }

        $this->components->info(sprintf('Queued %d delivery attempt(s).', $queued));

        return self::SUCCESS;
    }
}
