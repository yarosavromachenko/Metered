<?php

declare(strict_types=1);

namespace Metered\Webhooks\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Metered\Webhooks\Domain\Delivery\DeliveryStatus;

/**
 * Read model for the panel and the API.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $endpoint_id
 * @property string $event_id
 * @property string $event_type
 * @property string $body
 * @property DeliveryStatus $status
 * @property int $attempts
 * @property DateTimeImmutable|null $next_attempt_at
 * @property int|null $last_status_code
 * @property DateTimeImmutable|null $last_attempt_at
 * @property DateTimeImmutable $created_at
 * @property WebhookEndpoint $endpoint
 * @property Collection<int, WebhookAttempt> $attemptLog
 */
final class WebhookDelivery extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'webhook_deliveries';

    protected $guarded = [];

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'endpoint_id');
    }

    /**
     * @return HasMany<WebhookAttempt, $this>
     */
    public function attemptLog(): HasMany
    {
        return $this->hasMany(WebhookAttempt::class, 'delivery_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'attempts' => 'integer',
            'last_status_code' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
