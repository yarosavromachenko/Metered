<?php

declare(strict_types=1);

namespace Metered\Invoicing\Infrastructure\Persistence;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Metered\Invoicing\Domain\Invoice\BillTo;
use Metered\Invoicing\Domain\Invoice\DocumentNumber;
use Metered\Invoicing\Domain\Invoice\Invoice;
use Metered\Invoicing\Domain\Invoice\InvoiceLine;
use Metered\Invoicing\Domain\Invoice\InvoicePeriod;
use Metered\Invoicing\Domain\Invoice\InvoiceRepository;
use Metered\Invoicing\Domain\Invoice\InvoiceStatus;
use Metered\Invoicing\Domain\Invoice\LineKind;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Quantity\Quantity;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Infrastructure\Persistence\RowReader;
use stdClass;

final readonly class DatabaseInvoiceRepository implements InvoiceRepository
{
    /**
     * Keeps microseconds; the unique key compares period instants exactly.
     */
    public const string INSTANT = 'Y-m-d H:i:s.uP';

    public function __construct(private DatabaseManager $db) {}

    public function add(Invoice $invoice): bool
    {
        $connection = $this->db->connection();

        // ON CONFLICT DO NOTHING: a failed statement would abort the caller's
        // transaction.
        $inserted = $connection->table('invoices')->insertOrIgnore([
            'id' => $invoice->id->value,
            'organization_id' => $invoice->tenant->organizationId->value,
            'project_id' => $invoice->tenant->projectId->value,
            'customer_id' => $invoice->customerId->value,
            'customer_ref' => $invoice->billTo->reference,
            'customer_name' => $invoice->billTo->name,
            'subscription_id' => $invoice->subscriptionId->value,
            'currency' => $invoice->currency,
            'period_start' => $invoice->period->start->format(self::INSTANT),
            'period_end' => $invoice->period->end->format(self::INSTANT),
            'status' => $invoice->status->value,
            'number' => $invoice->number?->sequence,
            'total_minor' => $invoice->total()->minorUnits(),
            'built_at' => $invoice->builtAt->format(self::INSTANT),
            'finalized_at' => $invoice->finalizedAt?->format(self::INSTANT),
            'paid_at' => $invoice->paidAt?->format(self::INSTANT),
            'voided_at' => $invoice->voidedAt?->format(self::INSTANT),
        ]);

        if ($inserted === 0) {
            return false;
        }

        if ($invoice->lines !== []) {
            $connection->table('invoice_lines')->insert(array_map(
                fn(InvoiceLine $line, int $position): array => $this->lineRow($invoice, $line, $position),
                $invoice->lines,
                array_keys($invoice->lines),
            ));
        }

        return true;
    }

    public function save(Invoice $invoice): void
    {
        $this->scoped($invoice->tenant)->where('id', $invoice->id->value)->update([
            'status' => $invoice->status->value,
            'number' => $invoice->number?->sequence,
            'finalized_at' => $invoice->finalizedAt?->format(self::INSTANT),
            'paid_at' => $invoice->paidAt?->format(self::INSTANT),
            'voided_at' => $invoice->voidedAt?->format(self::INSTANT),
        ]);
    }

    public function find(TenantContext $tenant, Uuid $id): ?Invoice
    {
        $row = $this->scoped($tenant)->where('id', $id->value)->first();

        return $row instanceof stdClass ? $this->toInvoice($row) : null;
    }

    public function findForUpdate(TenantContext $tenant, Uuid $id): ?Invoice
    {
        $row = $this->scoped($tenant)->where('id', $id->value)->lockForUpdate()->first();

        return $row instanceof stdClass ? $this->toInvoice($row) : null;
    }

    public function latestFor(TenantContext $tenant, Uuid $subscriptionId): ?Invoice
    {
        $row = $this->scoped($tenant)
            ->where('subscription_id', $subscriptionId->value)
            ->orderByDesc('period_end')
            ->first();

        return $row instanceof stdClass ? $this->toInvoice($row) : null;
    }

    private function scoped(TenantContext $tenant): Builder
    {
        return $this->db->connection()->table('invoices')
            ->where('project_id', $tenant->projectId->value)
            ->where('organization_id', $tenant->organizationId->value);
    }

    /**
     * @return array<string, mixed>
     */
    private function lineRow(Invoice $invoice, InvoiceLine $line, int $position): array
    {
        return [
            'invoice_id' => $invoice->id->value,
            'position' => $position,
            'organization_id' => $invoice->tenant->organizationId->value,
            'project_id' => $invoice->tenant->projectId->value,
            'subscription_id' => $invoice->subscriptionId->value,
            'kind' => $line->kind->value,
            'description' => $line->description,
            'amount_minor' => $line->amount->minorUnits(),
            'currency' => $line->amount->currency(),
            'covers_start' => $line->covers->start->format(self::INSTANT),
            'covers_end' => $line->covers->end->format(self::INSTANT),
            'price_id' => $line->priceId->value,
            'meter_id' => $line->meterId?->value,
            'meter_code' => $line->meterCode,
            'quantity' => $line->quantity instanceof Quantity ? (string) $line->quantity : null,
            'calculation' => json_encode($line->calculation, JSON_THROW_ON_ERROR),
        ];
    }

    private function toInvoice(stdClass $row): Invoice
    {
        $values = get_object_vars($row);
        $id = RowReader::string($values['id'] ?? null, 'id');
        $currency = RowReader::string($values['currency'] ?? null, 'currency');
        $number = $values['number'] ?? null;

        $lines = [];

        foreach ($this->db->connection()->table('invoice_lines')->where('invoice_id', $id)->orderBy('position')->get() as $line) {
            if ($line instanceof stdClass) {
                $lines[] = $this->toLine($line);
            }
        }

        return Invoice::restore(
            Uuid::fromString($id),
            new TenantContext(
                Uuid::fromString(RowReader::string($values['organization_id'] ?? null, 'organization_id')),
                Uuid::fromString(RowReader::string($values['project_id'] ?? null, 'project_id')),
            ),
            Uuid::fromString(RowReader::string($values['customer_id'] ?? null, 'customer_id')),
            new BillTo(
                RowReader::string($values['customer_ref'] ?? null, 'customer_ref'),
                RowReader::string($values['customer_name'] ?? null, 'customer_name'),
            ),
            Uuid::fromString(RowReader::string($values['subscription_id'] ?? null, 'subscription_id')),
            $currency,
            InvoicePeriod::between(
                RowReader::instant($values['period_start'] ?? null, 'period_start'),
                RowReader::instant($values['period_end'] ?? null, 'period_end'),
            ),
            $lines,
            InvoiceStatus::from(RowReader::string($values['status'] ?? null, 'status')),
            $number === null ? null : DocumentNumber::invoice(RowReader::int($number, 'number')),
            RowReader::instant($values['built_at'] ?? null, 'built_at'),
            RowReader::instantOrNull($values['finalized_at'] ?? null, 'finalized_at'),
            RowReader::instantOrNull($values['paid_at'] ?? null, 'paid_at'),
            RowReader::instantOrNull($values['voided_at'] ?? null, 'voided_at'),
        );
    }

    private function toLine(stdClass $row): InvoiceLine
    {
        $values = get_object_vars($row);
        $meterId = $values['meter_id'] ?? null;
        $quantity = $values['quantity'] ?? null;

        return InvoiceLine::restore(
            LineKind::from(RowReader::string($values['kind'] ?? null, 'kind')),
            RowReader::string($values['description'] ?? null, 'description'),
            Money::ofMinorUnits(RowReader::int($values['amount_minor'] ?? null, 'amount_minor'), RowReader::string($values['currency'] ?? null, 'currency')),
            InvoicePeriod::between(
                RowReader::instant($values['covers_start'] ?? null, 'covers_start'),
                RowReader::instant($values['covers_end'] ?? null, 'covers_end'),
            ),
            Uuid::fromString(RowReader::string($values['price_id'] ?? null, 'price_id')),
            $meterId === null ? null : Uuid::fromString(RowReader::string($meterId, 'meter_id')),
            isset($values['meter_code']) ? RowReader::string($values['meter_code'], 'meter_code') : null,
            $quantity === null ? null : Quantity::fromString(RowReader::string($quantity, 'quantity')),
            RowReader::stringList($values['calculation'] ?? null, 'calculation'),
        );
    }
}
