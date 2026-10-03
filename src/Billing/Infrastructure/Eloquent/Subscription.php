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
use Metered\Billing\Domain\Subscription\SubscriptionStatus;

/**
 * Read model.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $customer_id
 * @property DateTimeImmutable $anchor_at
 * @property string $currency
 * @property BillingInterval $interval
 * @property SubscriptionStatus $status
 * @property DateTimeImmutable|null $ends_at
 * @property Customer $customer
 * @property Collection<int, SubscriptionPhase> $phases
 */
final class Subscription extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'subscriptions';

    protected $guarded = [];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /**
     * @return HasMany<SubscriptionPhase, $this>
     */
    public function phases(): HasMany
    {
        return $this->hasMany(SubscriptionPhase::class, 'subscription_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'interval' => BillingInterval::class,
            'anchor_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }
}
