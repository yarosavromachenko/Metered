<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Inbox;

use Metered\Shared\Domain\Outbox\OutboxMessage;

/**
 * The dispatcher wraps every call in the inbox guard, keyed by
 * {@see self::consumer()}.
 */
interface IntegrationEventHandler
{
    /**
     * Inbox key. Persisted, so it is not the class name: a rename must not make
     * processed messages look new.
     */
    public function consumerName(): string;

    /**
     * @return list<string> the event types this handler wants
     */
    public function subscribesTo(): array;

    public function handle(OutboxMessage $message): void;
}
