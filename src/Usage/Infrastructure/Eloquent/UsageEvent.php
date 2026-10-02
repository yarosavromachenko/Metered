<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Read model for the usage explorer; events are written only by the
 * consumer. `$primaryKey` is nominal, the table has a composite key. No
 * relations to Billing models (ADR-0015): the row has the meter code and
 * customer reference.
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
