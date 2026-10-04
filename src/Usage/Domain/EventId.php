<?php

declare(strict_types=1);

namespace Metered\Usage\Domain;

use Metered\Usage\Domain\Exception\InvalidEventId;
use Stringable;

/**
 * The client's id for an event, the deduplication key (ADR-0002). Kept as
 * sent, case included; at most 128 characters since it is in a unique index.
 */
final readonly class EventId implements Stringable
{
    public const int MAX_LENGTH = 128;

    private function __construct(public string $value) {}

    public function __toString(): string
    {
        return $this->value;
    }

    public static function fromString(string $value): self
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw InvalidEventId::empty();
        }

        if (strlen($trimmed) > self::MAX_LENGTH) {
            throw InvalidEventId::tooLong($trimmed);
        }

        if (preg_match('/^[\x21-\x7E]+$/', $trimmed) !== 1) {
            throw InvalidEventId::malformed($trimmed);
        }

        return new self($trimmed);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
