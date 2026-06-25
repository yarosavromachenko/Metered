<?php

declare(strict_types=1);

namespace Metered\Tenancy\Presentation\Filament;

use Illuminate\Support\Facades\Auth;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Application\Authorization\Actor;
use RuntimeException;

/**
 * The signed-in person, as the actor a command carries.
 *
 * Every panel write names one, and it is read from the session rather than
 * from anything the browser sent: an actor taken from a form field would be a
 * request to act as somebody else.
 */
final class PanelActor
{
    public static function current(): Actor
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();
        $email = is_object($user) && property_exists($user, 'email') ? $user->email : null;

        if (! is_string($id) || ! Uuid::isValid($id) || ! is_string($email)) {
            throw new RuntimeException('This action needs a signed-in user and there is none.');
        }

        return Actor::user(Uuid::fromString($id), $email);
    }
}
