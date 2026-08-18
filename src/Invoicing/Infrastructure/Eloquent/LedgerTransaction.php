<?php

declare(strict_types=1);

namespace Metered\Invoicing\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Metered\Invoicing\Domain\Ledger\Posting;

/**
 * Read model for the panel.
 *
 * @property string $id
 * @property string $invoice_id
 * @property Posting $posting
 * @property DateTimeImmutable $occurred_at
 * @property Invoice $invoice
 * @property Collection<int, LedgerEntry> $entries
 */
final class LedgerTransaction extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'ledger_transactions';

    protected $guarded = [];

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'transaction_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'posting' => Posting::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
