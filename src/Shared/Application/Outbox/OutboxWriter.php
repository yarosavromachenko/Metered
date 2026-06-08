<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Outbox;

use Metered\Shared\Domain\Outbox\OutboxMessage;

/**
 * Records an integration event for later publication.
 *
 * Implementations must write to the same database and the same transaction as
 * the state change being recorded. A writer that opens its own connection, or
 * queues the message anywhere but the database, silently reintroduces the gap
 * the outbox exists to close.
 */
interface OutboxWriter
{
    public function append(OutboxMessage $message): void;
}
