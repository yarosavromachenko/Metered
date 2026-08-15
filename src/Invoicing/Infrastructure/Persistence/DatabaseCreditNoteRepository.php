<?php

declare(strict_types=1);

namespace Metered\Invoicing\Infrastructure\Persistence;

use Illuminate\Database\DatabaseManager;
use Metered\Invoicing\Domain\CreditNote\CreditNote;
use Metered\Invoicing\Domain\CreditNote\CreditNoteRepository;
use Metered\Invoicing\Domain\Invoice\DocumentNumber;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Infrastructure\Persistence\RowReader;
use stdClass;

final readonly class DatabaseCreditNoteRepository implements CreditNoteRepository
{
    public function __construct(private DatabaseManager $db) {}

    public function add(CreditNote $note): void
    {
        $this->db->connection()->table('credit_notes')->insert([
            'id' => $note->id->value,
            'organization_id' => $note->tenant->organizationId->value,
            'project_id' => $note->tenant->projectId->value,
            'customer_id' => $note->customerId->value,
            'invoice_id' => $note->invoiceId->value,
            'number' => $note->number->sequence,
            'amount_minor' => $note->amount->minorUnits(),
            'currency' => $note->amount->currency(),
            'reason' => $note->reason,
            'issued_at' => $note->issuedAt->format(DatabaseInvoiceRepository::INSTANT),
        ]);
    }

    public function forInvoice(TenantContext $tenant, Uuid $invoiceId): ?CreditNote
    {
        $row = $this->db->connection()->table('credit_notes')
            ->where('project_id', $tenant->projectId->value)
            ->where('organization_id', $tenant->organizationId->value)
            ->where('invoice_id', $invoiceId->value)
            ->first();

        if (! $row instanceof stdClass) {
            return null;
        }

        $values = get_object_vars($row);

        return CreditNote::restore(
            Uuid::fromString(RowReader::string($values['id'] ?? null, 'id')),
            $tenant,
            $invoiceId,
            Uuid::fromString(RowReader::string($values['customer_id'] ?? null, 'customer_id')),
            DocumentNumber::creditNote(RowReader::int($values['number'] ?? null, 'number')),
            Money::ofMinorUnits(RowReader::int($values['amount_minor'] ?? null, 'amount_minor'), RowReader::string($values['currency'] ?? null, 'currency')),
            RowReader::string($values['reason'] ?? null, 'reason'),
            RowReader::instant($values['issued_at'] ?? null, 'issued_at'),
        );
    }
}
