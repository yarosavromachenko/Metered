<?php

declare(strict_types=1);

namespace Metered\Billing\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Read model for the panel. Writes go through the application handlers.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $code
 * @property string $name
 * @property DateTimeImmutable $created_at
 */
final class Plan extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'plans';

    protected $guarded = [];

    /**
     * @return HasMany<PlanVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(PlanVersion::class, 'plan_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
