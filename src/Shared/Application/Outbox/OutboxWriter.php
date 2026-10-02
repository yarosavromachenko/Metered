<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Outbox;

use Metered\Shared\Domain\Outbox\OutboxMessage;

/**
 * Implementations write in the caller's transaction, on the same connection.
 */
interface OutboxWriter
{
    public function append(OutboxMessage $message): void;
}
