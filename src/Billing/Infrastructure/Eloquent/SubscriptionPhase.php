<?php

declare(strict_types=1);

namespace Metered\Billing\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read model for the panel. The table is keyed by (subscription, start),
 * which Eloquent cannot address, so this model is only ever loaded through
 * its subscription and never saved.
 *
 * @property string $subscription_id
 * @property string $plan_version_id
 * @property DateTimeImmutable $starts_at
 * @property DateTimeImmutable|null $ends_at
 * @property PlanVersion $planVersion
 */
final class SubscriptionPhase extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'subscription_phases';

    protected $primaryKey = 'starts_at';

    protected $guarded = [];

    /**
     * @return BelongsTo<PlanVersion, $this>
     */
    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class, 'plan_version_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }
}
