<?php

declare(strict_types=1);

namespace Metered\Tenancy\Infrastructure\Eloquent;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Authenticatable for the session guard and Filament. Permissions come from
 * Membership.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property-read Collection<int, OrganizationMember> $memberships
 */
final class User extends Authenticatable implements FilamentUser
{
    use HasUuids;
    use Notifiable;

    protected $table = 'users';

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    /**
     * Any membership is enough; roles are checked in the handlers.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        // toBase(): PHPStan cannot follow the relation's magic count().
        return $this->memberships()->toBase()->exists();
    }

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
