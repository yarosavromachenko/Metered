<?php

declare(strict_types=1);

namespace Metered\Billing\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Metered\Billing\Domain\Period\BillingInterval;

/**
 * Read model.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $plan_id
 * @property int $number
 * @property string $currency
 * @property BillingInterval $interval
 * @property DateTimeImmutable $created_at
 * @property DateTimeImmutable|null $published_at
 * @property Plan $plan
 * @property Collection<int, Price> $prices
 */
final class PlanVersion extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'plan_versions';

    protected $guarded = [];

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    /**
     * @return HasMany<Price, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(Price::class, 'plan_version_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'interval' => BillingInterval::class,
            'number' => 'integer',
            'created_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }
}
