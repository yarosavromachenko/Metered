<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Outbox;

use Metered\Shared\Domain\Outbox\OutboxMessage;

/**
 * At-least-once: the relay may publish and fail before marking the message
 * published. Consumers deduplicate through the inbox.
 */
interface OutboxPublisher
{
    public function publish(OutboxMessage $message): void;
}
