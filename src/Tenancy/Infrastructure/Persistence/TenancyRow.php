<?php

declare(strict_types=1);

namespace Metered\Tenancy\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Metered\Shared\Infrastructure\Outbox\RowReader;

/**
 * The few conversions the three tenancy repositories share.
 *
 * Timestamps come back from PostgreSQL with the session's offset attached.
 * They are moved to UTC here rather than compared as they arrive: the domain
 * compares instants, and two instants that differ only in offset must not
 * compare as different ones.
 */
final class TenancyRow
{
    public static function instant(mixed $value, string $column): DateTimeImmutable
    {
        return new DateTimeImmutable(RowReader::string($value, $column), new DateTimeZone('UTC'));
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
        $decoded = RowReader::jsonObject($value, $column);
        $strings = [];

        foreach ($decoded as $key => $item) {
            $strings[] = RowReader::string($item, $column . '.' . $key);
        }

        return $strings;
    }
}
