<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Inbox;

use Metered\Shared\Application\Inbox\InboxGuard;
use Metered\Shared\Application\Inbox\IntegrationEventHandler;
use Metered\Shared\Domain\Outbox\OutboxMessage;

/**
 * Routes an integration event to the handlers that asked for it, each behind
 * its own inbox claim.
 *
 * Keying the claim by handler rather than by message is what lets two modules
 * react to the same event: each gets exactly one turn, and a redelivery gives
 * neither a second one. The key is the handler's declared name rather than its
 * class, so moving or renaming the class does not make every message it has
 * already processed look new.
 */
final readonly class IntegrationEventDispatcher
{
    /**
     * @param  iterable<IntegrationEventHandler>  $handlers
     */
    public function __construct(
        private iterable $handlers,
        private InboxGuard $guard,
    ) {}

    /**
     * @return int how many handlers actually ran, as opposed to being skipped
     *             because this message had already been processed
     */
    public function dispatch(OutboxMessage $message): int
    {
        $ran = 0;

        foreach ($this->handlers as $handler) {
            if (! in_array($message->type, $handler->subscribesTo(), true)) {
                continue;
            }

            $executed = $this->guard->once(
                $handler->consumerName(),
                $message->id,
                static function () use ($handler, $message): void {
                    $handler->handle($message);
                },
            );

            if ($executed) {
                $ran++;
            }
        }

        return $ran;
    }
}
