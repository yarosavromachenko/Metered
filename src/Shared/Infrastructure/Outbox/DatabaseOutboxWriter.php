<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Outbox;

use Illuminate\Database\DatabaseManager;
use JsonException;
use Metered\Shared\Application\Outbox\OutboxWriter;
use Metered\Shared\Domain\Outbox\OutboxMessage;
use Metered\Shared\Infrastructure\Tracing\Tracing;
use RuntimeException;

/**
 * Writes the message on the default connection, which is deliberately the same
 * connection — and therefore the same transaction — as the state change that
 * produced it.
 *
 * Nothing here opens a connection of its own. That is the whole guarantee: if
 * the business transaction rolls back, the message goes with it.
 *
 * The trace context of whatever is writing — a request, a job — is added to
 * the message's headers here, so that producers stay free of tracing and the
 * relay can continue the trace that caused the event (ADR-0012). Headers the
 * producer set itself win.
 */
final readonly class DatabaseOutboxWriter implements OutboxWriter
{
    public function __construct(
        private DatabaseManager $db,
        private Tracing $tracing,
    ) {}

    public function append(OutboxMessage $message): void
    {
        $this->db->connection()->table('outbox_messages')->insert([
            'id' => $message->id->value,
            'aggregate_type' => $message->aggregateType,
            'aggregate_id' => $message->aggregateId->value,
            'type' => $message->type,
            'payload' => $this->encode($message->payload),
            'headers' => $this->encode($message->headers + $this->tracing->carrier()),
            'occurred_at' => $message->occurredAt,
            'published_at' => null,
            'attempts' => $message->attempts,
        ]);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function encode(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('An outbox payload must be JSON-encodable; this one was not.', $e->getCode(), previous: $e);
        }
    }
}
