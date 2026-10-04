<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;

/**
 * Typed access to query-builder rows; throws when a column is missing or has
 * the wrong type.
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
     * Converted to UTC: the offset in the string wins over a zone passed to
     * the constructor.
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
