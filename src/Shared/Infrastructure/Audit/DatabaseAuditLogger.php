<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Audit;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use JsonException;
use Metered\Shared\Application\Audit\AuditLogger;
use Metered\Shared\Domain\Audit\AuditEntry;
use Metered\Shared\Domain\Audit\ChainHash;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Infrastructure\Persistence\RowReader;
use RuntimeException;

/**
 * Appends to the chain.
 *
 * Appending needs the previous hash, so two concurrent writers would otherwise
 * build two entries claiming the same predecessor. A transaction-scoped
 * advisory lock serialises exactly that step — transaction-scoped because
 * PgBouncer's transaction pooling would lose a session-scoped one.
 */
final readonly class DatabaseAuditLogger implements AuditLogger
{
    private const int LOCK_KEY = 0x4155_4449; // "AUDI"

    public function __construct(
        private DatabaseManager $db,
        private IdentifierGenerator $ids,
    ) {}

    public function record(AuditEntry $entry): void
    {
        $this->db->connection()->transaction(function (ConnectionInterface $tx) use ($entry): void {
            $tx->select('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY]);

            $previous = $tx->table('audit_log')->orderByDesc('sequence')->first(['hash']);

            $previousHash = $previous === null
                ? ChainHash::GENESIS
                : RowReader::string(get_object_vars($previous)['hash'] ?? null, 'hash');

            $hash = ChainHash::compute(
                $previousHash,
                $entry->actor,
                $entry->action,
                $entry->subjectType,
                $entry->subjectId,
                $entry->payload,
                $entry->occurredAt,
            );

            $tx->table('audit_log')->insert([
                'id' => $this->ids->generate()->value,
                'actor' => $entry->actor,
                'action' => $entry->action,
                'subject_type' => $entry->subjectType,
                'subject_id' => $entry->subjectId,
                'payload' => $this->encode($entry->payload),
                // Formatted here rather than handed over as a DateTimeInterface:
                // the query grammar would render it to the second, and the hash
                // covers microseconds.
                'occurred_at' => $entry->occurredAt->format('Y-m-d H:i:s.uP'),
                'prev_hash' => $previousHash,
                'hash' => $hash,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('An audit payload must be JSON-encodable.', $e->getCode(), previous: $e);
        }
    }
}
