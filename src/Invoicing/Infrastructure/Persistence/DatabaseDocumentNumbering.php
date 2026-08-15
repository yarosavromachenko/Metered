<?php

declare(strict_types=1);

namespace Metered\Invoicing\Infrastructure\Persistence;

use Illuminate\Database\DatabaseManager;
use Metered\Invoicing\Domain\Invoice\DocumentNumber;
use Metered\Invoicing\Domain\Invoice\DocumentNumbering;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Infrastructure\Persistence\RowReader;
use RuntimeException;

/**
 * One statement per number: the upsert creates the organization's counter at
 * one or advances it, and holds its row lock until the caller's transaction
 * ends. A second finalization in the same organization waits on that lock and
 * reads the committed value — or, after a rollback, the same number again.
 * That is the whole of the gapless guarantee (ADR-0010).
 */
final readonly class DatabaseDocumentNumbering implements DocumentNumbering
{
    public function __construct(private DatabaseManager $db) {}

    public function nextInvoiceNumber(Uuid $organizationId): DocumentNumber
    {
        return DocumentNumber::invoice($this->next($organizationId, 'invoice'));
    }

    public function nextCreditNoteNumber(Uuid $organizationId): DocumentNumber
    {
        return DocumentNumber::creditNote($this->next($organizationId, 'credit_note'));
    }

    private function next(Uuid $organizationId, string $kind): int
    {
        $connection = $this->db->connection();

        if ($connection->transactionLevel() === 0) {
            throw new RuntimeException('A document number must be taken inside the transaction that uses it.');
        }

        $row = $connection->selectOne(
            'INSERT INTO document_sequences (organization_id, kind, last_number) VALUES (?, ?, 1)
             ON CONFLICT (organization_id, kind) DO UPDATE SET last_number = document_sequences.last_number + 1
             RETURNING last_number',
            [$organizationId->value, $kind],
        );

        if (! is_object($row)) {
            throw new RuntimeException('The document counter returned no row.');
        }

        return RowReader::int(get_object_vars($row)['last_number'] ?? null, 'last_number');
    }
}
