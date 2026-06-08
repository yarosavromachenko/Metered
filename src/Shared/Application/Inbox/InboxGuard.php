<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Inbox;

use Metered\Shared\Domain\Identifier\Uuid;

/**
 * Makes a consumer idempotent.
 *
 * Delivery is at-least-once, so a consumer will see the same message twice.
 * The guard records (consumer, message) under a unique constraint and runs the
 * handler only the first time — the second caller is told it has already been
 * done rather than doing it again.
 */
interface InboxGuard
{
    /**
     * @param  callable():void  $handler
     * @return bool whether the handler ran; false means this message was already processed
     */
    public function once(string $consumer, Uuid $messageId, callable $handler): bool;
}
