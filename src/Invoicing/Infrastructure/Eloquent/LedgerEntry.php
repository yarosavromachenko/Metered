<?php

declare(strict_types=1);

namespace Metered\Invoicing\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Metered\Invoicing\Domain\Ledger\Account;
use Metered\Invoicing\Domain\Ledger\Direction;
use Metered\Shared\Domain\Money\Money;

/**
 * Read model for the panel.
 *
 * @property int $id
 * @property string $transaction_id
 * @property string $organization_id
 * @property string $project_id
 * @property string $customer_id
 * @property Account $account
 * @property Direction $direction
 * @property int $amount_minor
 * @property string $currency
 * @property DateTimeImmutable $occurred_at
 * @property LedgerTransaction $transaction
 */
final class LedgerEntry extends Model
{
    public $timestamps = false;

    protected $table = 'ledger_entries';

    protected $guarded = [];

    /**
     * @return BelongsTo<LedgerTransaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'transaction_id');
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
            'account' => Account::class,
            'direction' => Direction::class,
            'amount_minor' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
