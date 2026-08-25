<?php

declare(strict_types=1);

namespace Metered\Webhooks\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Metered\Webhooks\Domain\Endpoint\BreakerState;

/**
 * Read model for the panel and the API. The secret columns are hidden: they
 * are ciphertext, and nothing that reads this model has any use for them.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $url
 * @property string $description
 * @property list<string> $event_types
 * @property bool $enabled
 * @property BreakerState $breaker_state
 * @property int $consecutive_failures
 * @property DateTimeImmutable|null $breaker_changed_at
 * @property DateTimeImmutable|null $previous_secret_expires_at
 * @property DateTimeImmutable $created_at
 */
final class WebhookEndpoint extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'webhook_endpoints';

    protected $guarded = [];

    protected $hidden = ['secret', 'previous_secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_types' => 'array',
            'enabled' => 'boolean',
            'breaker_state' => BreakerState::class,
            'consecutive_failures' => 'integer',
            'breaker_changed_at' => 'immutable_datetime',
            'previous_secret_expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
