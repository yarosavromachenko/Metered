<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Inbox;

use Metered\Shared\Domain\Outbox\OutboxMessage;

/**
 * Something that reacts to an integration event published by another module.
 *
 * Handlers do not deduplicate for themselves. The dispatcher wraps every call
 * in the inbox guard, keyed by the handler's own name, so two handlers of the
 * same event each get their turn while neither gets two.
 */
interface IntegrationEventHandler
{
    /**
     * The name this handler is remembered by in the inbox.
     *
     * Deliberately not the class name. A consumer key is persisted state: if it
     * changed when the class were renamed or moved, every message the handler
     * had already processed would look new, and it would process them all a
     * second time. The name is chosen once and kept.
     */
    public function consumerName(): string;

    /**
     * @return list<string> the event types this handler wants
     */
    public function subscribesTo(): array;

    public function handle(OutboxMessage $message): void;
}
