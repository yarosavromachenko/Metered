<?php

declare(strict_types=1);

namespace Metered\Billing\Domain;

use Metered\Billing\Domain\Exception\InvalidCustomerReference;
use Stringable;

/**
 * The id a tenant already knows their customer by.
 *
 * It is a foreign key into somebody else's system — a Stripe id, a row id, an
 * account slug — so this type stays out of the way: it trims, it bounds the
 * length, and it refuses only what cannot travel safely (whitespace inside,
 * control characters). It deliberately does not fold case, because two
 * references differing only in case may be two different customers over there,
 * and merging them here would be our error, not theirs.
 */
final readonly class CustomerReference implements Stringable
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
            throw InvalidCustomerReference::empty();
        }

        if (strlen($trimmed) > self::MAX_LENGTH) {
            throw InvalidCustomerReference::tooLong($trimmed);
        }

        // Visible ASCII only. A reference travels in a URL, a log line and a
        // problem document, and the three disagree about everything else.
        if (preg_match('/^[\x21-\x7E]+$/', $trimmed) !== 1) {
            throw InvalidCustomerReference::malformed($trimmed);
        }

        return new self($trimmed);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
