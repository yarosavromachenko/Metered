<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Outbox;

use Illuminate\Contracts\Bus\Dispatcher;
use Metered\Shared\Application\Outbox\OutboxPublisher;
use Metered\Shared\Domain\Outbox\OutboxMessage;
use Metered\Shared\Infrastructure\Queue\DeliverIntegrationEvent;

/**
 * On failure the row stays unpublished and the next relay pass retries it.
 */
final readonly class QueueOutboxPublisher implements OutboxPublisher
{
    public function __construct(private Dispatcher $bus) {}

    public function publish(OutboxMessage $message): void
    {
        $this->bus->dispatch(DeliverIntegrationEvent::fromMessage($message));
    }
}
