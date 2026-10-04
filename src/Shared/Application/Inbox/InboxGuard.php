<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Inbox;

use Metered\Shared\Domain\Identifier\Uuid;

/**
 * Runs a handler once per (consumer, message), backed by a unique constraint.
 */
interface InboxGuard
{
    /**
     * @param  callable():void  $handler
     * @return bool whether the handler ran; false means this message was already processed
     */
    public function once(string $consumer, Uuid $messageId, callable $handler): bool;
}
