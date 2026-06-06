<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Identifier;

use Metered\Shared\Domain\Exception\InvalidIdentifier;
use Stringable;

/**
 * An RFC 9562 identifier.
 *
 * Every id in this system is a UUIDv7, whose leading 48 bits are a millisecond
 * timestamp. That is the reason for the choice: ids generated over time sort in
 * roughly the order they were created, so inserts land at the right-hand edge
 * of a B-tree instead of scattering across it. On a table taking a few thousand
 * rows a second, that difference is the difference between a healthy index and
 * one that is rewritten constantly.
 *
 * The type accepts any valid UUID rather than v7 alone, because data arrives
 * from migrations, fixtures and other systems. Where the version matters, ask
 * for it: {@see self::version()}.
 */
final readonly class Uuid implements Stringable
{
    private const string PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private function __construct(public string $value) {}

    public function __toString(): string
    {
        return $this->value;
    }

    public static function fromString(string $value): self
    {
        $normalised = self::normalise($value);

        if (preg_match(self::PATTERN, $normalised) !== 1) {
            throw InvalidIdentifier::notAUuid($value);
        }

        return new self($normalised);
    }

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, self::normalise($value)) === 1;
    }

    public function version(): int
    {
        // The pattern admits versions 1 to 8 only, so the nibble is always a
        // decimal digit and a plain cast is enough — no hex conversion needed.
        return (int) $this->value[14];
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    private static function normalise(string $value): string
    {
        return strtolower(trim($value));
    }
}
