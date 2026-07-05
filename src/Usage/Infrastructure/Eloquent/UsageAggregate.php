<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The read side of an aggregate.
 *
 * Its key is (project, customer, meter, bucket) — four columns, which
 * Eloquent does not model. The screen that lists these needs one string per
 * row, so the query selects one: a synthetic `id` built from the four, which
 * exists nowhere in the table and is never written back.
 *
 * That is honest for a read model and would be unacceptable for a write one,
 * which is why there is no write path here at all: aggregates are only ever
 * changed by the consumer's upsert, in the transaction that inserted the
 * events they were folded from.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $customer_id
 * @property string $meter_id
 * @property string $meter_code
 * @property string $customer_ref
 * @property DateTimeImmutable $bucket_start
 * @property string $quantity
 * @property int $event_count
 * @property DateTimeImmutable $updated_at
 */
final class UsageAggregate extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'usage_aggregates';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * The key is manufactured in the select list, so it exists as an output
     * name and not as a column. Anything that qualifies it with the table
     * name — which is what a table's tie-breaking ORDER BY does by default —
     * would ask PostgreSQL for a column that is not there.
     */
    public function getQualifiedKeyName(): string
    {
        return $this->getKeyName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bucket_start' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'event_count' => 'integer',
        ];
    }
}
