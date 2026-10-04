<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Inbox;

use Metered\Shared\Application\Inbox\InboxGuard;
use Metered\Shared\Application\Inbox\IntegrationEventHandler;
use Metered\Shared\Domain\Outbox\OutboxMessage;

/**
 * Each handler gets its own inbox claim, so several handlers can process the
 * same event once each.
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
     * @return int handlers that ran (not skipped as already processed)
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
