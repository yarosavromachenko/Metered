<?php

declare(strict_types=1);

namespace Metered\Billing\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Metered\Shared\Domain\Metering\Aggregation;

/**
 * The read side of a meter — what the panel lists. Writes go through
 * DefineMeterHandler, never through this model.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $code
 * @property string $name
 * @property Aggregation $aggregation
 * @property DateTimeImmutable $created_at
 */
final class Meter extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'meters';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'aggregation' => Aggregation::class,
            'created_at' => 'immutable_datetime',
        ];
    }
}
