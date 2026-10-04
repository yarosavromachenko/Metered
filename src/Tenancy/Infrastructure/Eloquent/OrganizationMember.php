<?php

declare(strict_types=1);

namespace Metered\Tenancy\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Metered\Tenancy\Domain\Role;

/**
 * Read model.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $user_id
 * @property Role $role
 * @property-read User $user
 * @property-read Organization $organization
 */
final class OrganizationMember extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'organization_members';

    protected $guarded = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'created_at' => 'immutable_datetime',
        ];
    }
}
