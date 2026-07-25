<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Filament\Actions;

use Metered\Shared\Domain\Identifier\Uuid;

/**
 * Reads the loosely typed arrays Filament hands an action.
 */
final class Form
{
    /**
     * An id that names nothing, for a select left empty or tampered with. The
     * handler then answers "not found" like for any id outside the project,
     * instead of each action inventing its own message.
     */
    private const string NOTHING = '00000000-0000-7000-8000-000000000000';

    public static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    public static function id(mixed $value): Uuid
    {
        return self::uuid($value) ?? Uuid::fromString(self::NOTHING);
    }

    public static function uuid(mixed $value): ?Uuid
    {
        return is_string($value) && Uuid::isValid($value) ? Uuid::fromString($value) : null;
    }
}
