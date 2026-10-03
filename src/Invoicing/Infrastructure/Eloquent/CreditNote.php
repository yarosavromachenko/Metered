<?php

declare(strict_types=1);

namespace Metered\Invoicing\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Metered\Invoicing\Domain\Invoice\DocumentNumber;
use Metered\Shared\Domain\Money\Money;

/**
 * Read model.
 *
 * @property string $id
 * @property string $invoice_id
 * @property int $number
 * @property int $amount_minor
 * @property string $currency
 * @property string $reason
 * @property DateTimeImmutable $issued_at
 */
final class CreditNote extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'credit_notes';

    protected $guarded = [];

    public function printedNumber(): string
    {
        return (string) DocumentNumber::creditNote($this->number);
    }

    public function amount(): Money
    {
        return Money::ofMinorUnits($this->amount_minor, $this->currency);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'amount_minor' => 'integer',
            'issued_at' => 'immutable_datetime',
        ];
    }
}
