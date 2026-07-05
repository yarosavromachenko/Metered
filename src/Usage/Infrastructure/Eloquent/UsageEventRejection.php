<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Metered\Usage\Domain\RejectionReason;

/**
 * The read side of a rejection — the screen a tenant opens when their totals
 * look short.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string|null $event_id
 * @property RejectionReason $reason
 * @property string $detail
 * @property array<string, mixed> $payload
 * @property DateTimeImmutable $rejected_at
 */
final class UsageEventRejection extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'usage_event_rejections';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => RejectionReason::class,
            'rejected_at' => 'immutable_datetime',
            'payload' => 'array',
        ];
    }
}
