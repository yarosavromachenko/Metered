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
 * One upsert per number; its row lock lasts until the caller commits, so
 * concurrent finalizations wait and a rollback frees the number (ADR-0010).
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
