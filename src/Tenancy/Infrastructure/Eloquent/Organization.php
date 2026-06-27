<?php

declare(strict_types=1);

namespace Metered\Tenancy\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The read side of an organization (ADR-0015: resources read their own
 * module's models; every write goes through a handler).
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property-read Collection<int, Project> $projects
 */
final class Organization extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'organizations';

    protected $guarded = [];

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return HasMany<OrganizationMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
