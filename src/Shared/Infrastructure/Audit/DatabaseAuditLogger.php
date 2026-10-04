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
 * Appends to the organization's chain, or the platform chain (ADR-0020).
 * A per-chain advisory lock serialises appends; it is transaction-scoped
 * because PgBouncer runs in transaction pooling. The unique key on
 * (organization_id, prev_hash) is the actual guarantee.
 */
final readonly class DatabaseAuditLogger implements AuditLogger
{
    /** First key of the two-key advisory lock ("AUDI"). */
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
                // The query grammar would drop microseconds, which the hash covers.
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
