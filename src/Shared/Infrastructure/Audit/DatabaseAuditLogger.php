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
 * Appends to an organization's chain (ADR-0020), or to the platform chain for
 * an entry that belongs to none.
 *
 * Appending needs the chain's previous hash, so two concurrent writers to one
 * chain would otherwise build two entries claiming the same predecessor. A
 * transaction-scoped advisory lock serialises exactly that step, per chain —
 * transaction-scoped because PgBouncer's transaction pooling would lose a
 * session-scoped one. Writers to different chains do not wait for each other.
 * Should the lock ever fail to serialise them, the unique key on
 * (organization_id, prev_hash) refuses the second link.
 */
final readonly class DatabaseAuditLogger implements AuditLogger
{
    /** The lock's namespace: the first half of the two-key form, "AUDI". */
    private const int LOCK_CLASS = 0x4155_4449;

    private const string PLATFORM_CHAIN = 'platform';

    public function __construct(
        private DatabaseManager $db,
        private IdentifierGenerator $ids,
    ) {}

    public function record(AuditEntry $entry): void
    {
        $organizationId = $entry->organizationId?->value;

        $this->db->connection()->transaction(function (ConnectionInterface $tx) use ($entry, $organizationId): void {
            $tx->select('SELECT pg_advisory_xact_lock(?, hashtext(?))', [self::LOCK_CLASS, $organizationId ?? self::PLATFORM_CHAIN]);

            $chain = $tx->table('audit_log');
            $organizationId === null ? $chain->whereNull('organization_id') : $chain->where('organization_id', $organizationId);
            $previous = $chain->orderByDesc('sequence')->first(['hash']);

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
                $organizationId,
            );

            $tx->table('audit_log')->insert([
                'id' => $this->ids->generate()->value,
                'organization_id' => $organizationId,
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
