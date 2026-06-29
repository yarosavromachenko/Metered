<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;

/**
 * Turns the untyped values a database row hands back into the types the domain
 * expects, and fails loudly when the row is not shaped as expected.
 *
 * The alternative — casting mixed values and hoping — puts a silent corruption
 * one schema change away.
 *
 * Every module's repositories read rows, so this lives in the kernel rather
 * than beside the first table that needed it.
 */
final class RowReader
{
    public static function string(mixed $value, string $column): string
    {
        if (! is_string($value)) {
            throw self::unexpected($column, 'string', $value);
        }

        return $value;
    }

    public static function int(mixed $value, string $column): int
    {
        if (is_int($value)) {
            return $value;
        }

        // PostgreSQL returns integers as strings through some PDO builds.
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw self::unexpected($column, 'int', $value);
    }

    /**
     * @return array<string, mixed>
     */
    public static function jsonObject(mixed $value, string $column): array
    {
        $raw = self::string($value, $column);

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('Column "%s" does not contain valid JSON.', $column), $e->getCode(), previous: $e);
        }

        if (! is_array($decoded)) {
            throw self::unexpected($column, 'JSON object', $value);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return array<string, string>
     */
    public static function jsonStringMap(mixed $value, string $column): array
    {
        $map = [];

        foreach (self::jsonObject($value, $column) as $key => $item) {
            $map[$key] = self::string($item, $column . '.' . $key);
        }

        return $map;
    }

    /**
     * Timestamps arrive with the session's offset attached, and the offset
     * inside the string wins over any zone passed alongside it — so the value
     * is converted rather than merely constructed. The instant is the same
     * either way; what this fixes is everything downstream that renders one,
     * from a panel column to an assertion in a test.
     */
    public static function instant(mixed $value, string $column): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');

        return new DateTimeImmutable(self::string($value, $column), $utc)->setTimezone($utc);
    }

    public static function instantOrNull(mixed $value, string $column): ?DateTimeImmutable
    {
        return $value === null ? null : self::instant($value, $column);
    }

    /**
     * @return list<string>
     */
    public static function stringList(mixed $value, string $column): array
    {
        $strings = [];

        foreach (self::jsonObject($value, $column) as $key => $item) {
            $strings[] = self::string($item, $column . '.' . $key);
        }

        return $strings;
    }

    private static function unexpected(string $column, string $expected, mixed $value): RuntimeException
    {
        return new RuntimeException(sprintf(
            'Column "%s" was expected to hold a %s, got %s.',
            $column,
            $expected,
            get_debug_type($value),
        ));
    }
}
