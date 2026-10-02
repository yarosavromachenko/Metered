<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Queue;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Metered\Shared\Application\Metrics\GaugeReading;
use Metered\Shared\Application\Metrics\GaugeSource;
use Metered\Shared\Application\Metrics\SharedMetrics;

/**
 * Pending jobs per queue.
 */
final readonly class QueueGauges implements GaugeSource
{
    /**
     * @param  list<string>  $queues
     */
    public function __construct(
        private QueueFactory $queue,
        private array $queues,
    ) {}

    public function read(): array
    {
        $readings = [];

        foreach ($this->queues as $name) {
            $readings[] = new GaugeReading(SharedMetrics::queueDepth(), $this->queue->connection()->size($name), ['queue' => $name]);
        }

        return $readings;
    }
}
