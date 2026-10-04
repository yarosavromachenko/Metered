<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Filament\Actions;

use Metered\Shared\Domain\Identifier\Uuid;

/**
 * Typed reads from Filament's form data.
 */
final class Form
{
    /**
     * Placeholder for an empty or tampered select; the handler answers "not found".
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
