<?php

declare(strict_types=1);

namespace Metered\Billing\Domain;

use Metered\Billing\Domain\Exception\InvalidCustomerReference;
use Stringable;

/**
 * The tenant's own id for a customer. Trimmed and length-checked; inner
 * whitespace and control characters are refused. Case is kept: the tenant's
 * system may treat `Acme` and `acme` as different customers.
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

        // Visible ASCII only: it appears in URLs, logs and problem documents.
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
