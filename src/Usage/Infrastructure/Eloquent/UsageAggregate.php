<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Read model. The key is four columns, so queries select a synthetic `id`.
 * Aggregates are written only by the consumer's upsert.
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
     * Unqualified: `id` exists only in the select list, not in the table.
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
