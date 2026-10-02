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
 * Claims (consumer, message) with INSERT ... ON CONFLICT DO NOTHING. The
 * handler runs in the same transaction, so if it throws the claim is rolled
 * back and the message can be redelivered.
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
