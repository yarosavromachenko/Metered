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
 * Writes on the default connection, inside the caller's transaction. Adds the
 * current trace context to the headers (ADR-0012); headers set by the
 * producer take precedence.
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
