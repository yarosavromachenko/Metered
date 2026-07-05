<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The read side of a stored event, for the usage explorer.
 *
 * There is no write side here and there never will be: events are inserted in
 * bulk by the consumer, in one statement, inside the transaction that folds
 * them into aggregates. An Eloquent `save()` on this table would bypass that
 * and produce an event no aggregate knows about.
 *
 * Its key is the natural one, so `$primaryKey` is a lie Eloquent needs. The
 * panel only ever lists and filters, and the table sets its own record key.
 *
 * No relations to the meter or the customer: they belong to Billing, and a
 * module may not reach into another module's models (ADR-0015). It does not
 * need to — the row carries the code and the reference the client sent, which
 * is also what makes the explorer searchable without a join.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $event_id
 * @property string $customer_id
 * @property string $meter_id
 * @property string $meter_code
 * @property string $customer_ref
 * @property string $quantity
 * @property DateTimeImmutable $occurred_at
 * @property DateTimeImmutable $received_at
 * @property array<string, scalar|null> $properties
 */
final class UsageEvent extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'usage_events';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'properties' => 'array',
        ];
    }
}
