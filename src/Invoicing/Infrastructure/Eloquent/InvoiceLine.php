<?php

declare(strict_types=1);

namespace Metered\Invoicing\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Metered\Invoicing\Domain\Invoice\LineKind;
use Metered\Shared\Domain\Money\Money;

/**
 * Read model for the panel. The table is keyed by (invoice, position), which
 * Eloquent cannot address, so a line is only ever loaded through its invoice.
 *
 * @property string $invoice_id
 * @property int $position
 * @property LineKind $kind
 * @property string $description
 * @property int $amount_minor
 * @property string $currency
 * @property DateTimeImmutable $covers_start
 * @property DateTimeImmutable $covers_end
 * @property string|null $meter_code
 * @property string|null $quantity
 * @property list<string> $calculation
 */
final class InvoiceLine extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'invoice_lines';

    protected $primaryKey = 'position';

    protected $guarded = [];

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
            'kind' => LineKind::class,
            'amount_minor' => 'integer',
            'covers_start' => 'immutable_datetime',
            'covers_end' => 'immutable_datetime',
            'calculation' => 'array',
        ];
    }
}
