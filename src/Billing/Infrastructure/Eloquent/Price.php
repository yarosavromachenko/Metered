<?php

declare(strict_types=1);

namespace Metered\Billing\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read model.
 *
 * @property string $id
 * @property string $plan_version_id
 * @property string|null $meter_id
 * @property int $position
 * @property string $model
 * @property string $currency
 * @property int|null $flat_amount
 * @property string|null $unit_price
 * @property string|null $tiers
 * @property Meter|null $meter
 */
final class Price extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'prices';

    protected $guarded = [];

    /**
     * @return BelongsTo<Meter, $this>
     */
    public function meter(): BelongsTo
    {
        return $this->belongsTo(Meter::class, 'meter_id');
    }
}
