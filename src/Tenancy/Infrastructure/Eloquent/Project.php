<?php

declare(strict_types=1);

namespace Metered\Tenancy\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Metered\Tenancy\Domain\Environment;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $slug
 * @property Environment $environment
 * @property string $currency
 */
final class Project extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'projects';

    protected $guarded = [];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<ApiKey, $this>
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'environment' => Environment::class,
            'created_at' => 'immutable_datetime',
        ];
    }
}
