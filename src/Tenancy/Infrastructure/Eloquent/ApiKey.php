<?php

declare(strict_types=1);

namespace Metered\Tenancy\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Metered\Tenancy\Domain\Environment;

/**
 * The read side of an API key. There is no secret to read: the table holds a
 * prefix and a hash, and this model exposes exactly what the panel shows.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $name
 * @property string $prefix
 * @property Environment $environment
 * @property list<string> $scopes
 * @property DateTimeImmutable|null $revoked_at
 * @property DateTimeImmutable|null $last_used_at
 */
final class ApiKey extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'api_keys';

    protected $guarded = [];

    // The column exists and holds a hash; there is no view or export in which
    // it belongs, so it never leaves the model.
    protected $hidden = ['secret_hash'];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'environment' => Environment::class,
            'scopes' => 'array',
            'created_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
        ];
    }
}
