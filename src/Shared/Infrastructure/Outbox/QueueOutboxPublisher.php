<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Outbox;

use Illuminate\Contracts\Bus\Dispatcher;
use Metered\Shared\Application\Outbox\OutboxPublisher;
use Metered\Shared\Domain\Outbox\OutboxMessage;
use Metered\Shared\Infrastructure\Queue\DeliverIntegrationEvent;

/**
 * Puts a claimed message on the queue.
 *
 * This is the only step of the chain that is allowed to fail without losing
 * anything: the relay records the failure and the row stays unpublished, so the
 * next pass tries again.
 */
final readonly class QueueOutboxPublisher implements OutboxPublisher
{
    public function __construct(private Dispatcher $bus) {}

    public function publish(OutboxMessage $message): void
    {
        $this->bus->dispatch(DeliverIntegrationEvent::fromMessage($message));
    }
}
