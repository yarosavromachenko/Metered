<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Inbox;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Metered\Shared\Application\Inbox\InboxGuard;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Identifier\Uuid;
use Psr\Clock\ClockInterface;

/**
 * Claims (consumer, message) with an atomic insert and runs the handler only
 * for the caller that won.
 *
 * The claim is INSERT ... ON CONFLICT DO NOTHING against a unique constraint,
 * not a SELECT followed by an INSERT. Check-then-act has a window between the
 * two statements, and under concurrent redelivery that window is exactly where
 * the double processing happens.
 *
 * The handler runs inside the claiming transaction on purpose: if it throws,
 * the claim rolls back with it and the message can be delivered again. A claim
 * that outlived a failed handler would silently swallow the work.
 */
final readonly class DatabaseInboxGuard implements InboxGuard
{
    public function __construct(
        private DatabaseManager $db,
        private IdentifierGenerator $ids,
        private ClockInterface $clock,
    ) {}

    public function once(string $consumer, Uuid $messageId, callable $handler): bool
    {
        return $this->db->connection()->transaction(
            function (ConnectionInterface $tx) use ($consumer, $messageId, $handler): bool {
                $claimed = $tx->table('inbox_messages')->insertOrIgnore([
                    'id' => $this->ids->generate()->value,
                    'consumer' => $consumer,
                    'message_id' => $messageId->value,
                    'processed_at' => $this->clock->now(),
                ]);

                if ($claimed === 0) {
                    return false;
                }

                $handler();

                return true;
            },
        );
    }
}
