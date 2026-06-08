<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Outbox;

use Metered\Shared\Domain\Outbox\OutboxMessage;

/**
 * Hands a claimed message to whatever carries it onward — in production, a
 * queue.
 *
 * Publication is at-least-once: the relay can publish and then fail before
 * recording that it did. Consumers are expected to be idempotent, which is what
 * the inbox is for.
 */
interface OutboxPublisher
{
    public function publish(OutboxMessage $message): void;
}
