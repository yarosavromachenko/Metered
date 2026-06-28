<?php

declare(strict_types=1);

namespace Metered\Tenancy\Presentation\Filament;

use Illuminate\Support\Facades\Auth;
use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Infrastructure\Eloquent\User;
use RuntimeException;

/**
 * The signed-in person, as the actor a command carries.
 *
 * Every panel write names one, and it is read from the guard rather than from
 * anything the browser sent: an actor taken from a form field would be a
 * request to act as somebody else.
 *
 * The model is matched by type rather than by reading properties off whatever
 * the guard returned. An Eloquent model's columns are attributes, not declared
 * properties, so a duck-typed check passes nothing and fails everyone.
 */
final class PanelActor
{
    public static function current(): Actor
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('This action needs a signed-in user and there is none.');
        }

        // Attributes, read as what they are: the model's columns arrive
        // untyped, and this is the boundary where they stop being untyped.
        $id = $user->getAttribute('id');
        $email = $user->getAttribute('email');

        if (! is_string($id) || ! is_string($email) || ! Uuid::isValid($id)) {
            throw new RuntimeException('The signed-in user is missing an id or an email.');
        }

        return Actor::user(Uuid::fromString($id), $email);
    }
}
