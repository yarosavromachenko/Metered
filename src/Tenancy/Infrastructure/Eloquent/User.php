<?php

declare(strict_types=1);

namespace Metered\Tenancy\Infrastructure\Eloquent;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
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
final class User extends Authenticatable implements FilamentUser
{
    use HasUuids;
    use Notifiable;

    protected $table = 'users';

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    /**
     * Whether this person may enter the panel at all.
     *
     * Belonging to an organization is the whole condition: everything the
     * panel shows belongs to one, and someone with no membership would see an
     * empty shell and a switcher with nothing in it. What they may do once
     * inside is their role's business, checked in the handlers.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        // toBase(): the relation's own count() resolves through Eloquent's
        // magic forwarding, which static analysis cannot follow.
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
