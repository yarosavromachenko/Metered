<?php

declare(strict_types=1);

namespace Metered\Tenancy\Presentation\Filament;

use Illuminate\Support\Facades\Auth;
use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Infrastructure\Eloquent\User;
use RuntimeException;

/**
 * The signed-in user as an Actor, from the auth guard, never from request input.
 */
final class PanelActor
{
    public static function current(): Actor
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('This action needs a signed-in user and there is none.');
        }

        $id = $user->getAttribute('id');
        $email = $user->getAttribute('email');

        if (! is_string($id) || ! is_string($email) || ! Uuid::isValid($id)) {
            throw new RuntimeException('The signed-in user is missing an id or an email.');
        }

        return Actor::user(Uuid::fromString($id), $email);
    }
}
