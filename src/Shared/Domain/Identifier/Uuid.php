<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Identifier;

use Metered\Shared\Domain\Exception\InvalidIdentifier;
use Stringable;

/**
 * RFC 9562 UUID. Generated ids are v7 (time-ordered, so inserts stay at the
 * end of the B-tree), but any version parses; check {@see self::version()}
 * where it matters.
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
        // PATTERN allows versions 1-8 only, so the nibble is a decimal digit.
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
