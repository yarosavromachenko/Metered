<?php

declare(strict_types=1);

namespace Metered\Tenancy\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A person who signs into the panel.
 *
 * The one Eloquent model the framework insists on: authentication, sessions
 * and Filament all expect an Authenticatable. Everything about what this
 * person may do lives elsewhere — in Membership, which is domain, and in the
 * policies built on it.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property-read \Illuminate\Database\Eloquent\Collection<int, OrganizationMember> $memberships
 */
final class User extends Authenticatable
{
    use HasUuids;
    use Notifiable;

    protected $table = 'users';

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    /**
     * @return HasMany<OrganizationMember, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_signed_in_at' => 'immutable_datetime',
        ];
    }
}
