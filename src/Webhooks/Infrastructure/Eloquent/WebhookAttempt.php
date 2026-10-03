<?php

declare(strict_types=1);

namespace Metered\Webhooks\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Read model.
 *
 * @property int $id
 * @property string $delivery_id
 * @property int $number
 * @property DateTimeImmutable $attempted_at
 * @property int $duration_ms
 * @property int|null $status_code
 * @property string|null $error
 * @property string $response_excerpt
 */
final class WebhookAttempt extends Model
{
    public $timestamps = false;

    protected $table = 'webhook_attempts';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'duration_ms' => 'integer',
            'status_code' => 'integer',
            'attempted_at' => 'immutable_datetime',
        ];
    }
}
