<?php

declare(strict_types=1);

namespace Metered\Invoicing\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Metered\Invoicing\Domain\Invoice\DocumentNumber;
use Metered\Invoicing\Domain\Invoice\InvoiceStatus;
use Metered\Shared\Domain\Money\Money;

/**
 * Read model.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $customer_id
 * @property string $customer_ref
 * @property string $customer_name
 * @property string $subscription_id
 * @property string $currency
 * @property DateTimeImmutable $period_start
 * @property DateTimeImmutable $period_end
 * @property InvoiceStatus $status
 * @property int|null $number
 * @property int $total_minor
 * @property DateTimeImmutable $built_at
 * @property DateTimeImmutable|null $finalized_at
 * @property DateTimeImmutable|null $paid_at
 * @property DateTimeImmutable|null $voided_at
 * @property Collection<int, InvoiceLine> $lines
 * @property CreditNote|null $creditNote
 * @property Collection<int, LedgerTransaction> $ledgerTransactions
 */
final class Invoice extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'invoices';

    protected $guarded = [];

    /**
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class, 'invoice_id');
    }

    /**
     * @return HasOne<CreditNote, $this>
     */
    public function creditNote(): HasOne
    {
        return $this->hasOne(CreditNote::class, 'invoice_id');
    }

    /**
     * @return HasMany<LedgerTransaction, $this>
     */
    public function ledgerTransactions(): HasMany
    {
        return $this->hasMany(LedgerTransaction::class, 'invoice_id');
    }

    /**
     * In print order.
     *
     * @return Collection<int, InvoiceLine>
     */
    public function orderedLines(): Collection
    {
        return $this->lines->sortBy('position')->values();
    }

    public function printedNumber(): ?string
    {
        return $this->number === null ? null : (string) DocumentNumber::invoice($this->number);
    }

    public function total(): Money
    {
        return Money::ofMinorUnits($this->total_minor, $this->currency);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'number' => 'integer',
            'total_minor' => 'integer',
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
            'built_at' => 'immutable_datetime',
            'finalized_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }
}
