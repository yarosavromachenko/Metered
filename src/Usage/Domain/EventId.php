<?php

declare(strict_types=1);

namespace Metered\Usage\Domain;

use Metered\Usage\Domain\Exception\InvalidEventId;
use Stringable;

/**
 * The client's own id for one event, and the thing that makes retrying safe.
 *
 * A timeout after a successful write is indistinguishable from a failure, so a
 * well-behaved client resends. Deduplication is therefore part of the contract
 * rather than an optimisation (ADR-0002), and this is the key it turns on: the
 * same event id for the same event, stable across retries.
 *
 * Kept exactly as sent, case included — it is the client's key, and two ids
 * differing only in case may be two events over there. Bounded at 128
 * characters because it sits in a unique index on a table meant to hold
 * hundreds of millions of rows.
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
