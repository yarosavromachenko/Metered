<?php

declare(strict_types=1);

namespace Metered\Billing\Domain;

use Metered\Billing\Domain\Exception\InvalidMeterCode;
use Stringable;

/**
 * The name a client's instrumentation calls a meter by.
 *
 * Unlike an internal id, this string is typed once into somebody else's code
 * and then sent on every event for years. That is why the shape is narrow —
 * lowercase letters, digits and single separators — and why it is the one
 * identifier in the system that is normalised rather than merely validated:
 * a deploy that changes `API.Calls` to `api.calls` must not silently start a
 * second meter, because the first anyone would hear of it is an invoice with
 * a month of usage missing.
 *
 * A customer reference, by contrast, keeps its case ({@see CustomerReference}):
 * it points into the tenant's own system, where the distinction may be real.
 */
final readonly class MeterCode implements Stringable
{
    public const int MAX_LENGTH = 64;
    private const string PATTERN = '/^[a-z0-9]+([._-][a-z0-9]+)*$/';

    private const int MIN_LENGTH = 2;

    private function __construct(public string $value) {}

    public function __toString(): string
    {
        return $this->value;
    }

    public static function fromString(string $value): self
    {
        $normalised = strtolower(trim($value));

        // Shape first, then length: "a" is a well-formed code that is merely
        // too short, and telling its author about the character set would send
        // them looking at the wrong thing.
        if (preg_match(self::PATTERN, $normalised) !== 1) {
            throw InvalidMeterCode::malformed($value);
        }

        if (strlen($normalised) < self::MIN_LENGTH) {
            throw InvalidMeterCode::tooShort($normalised);
        }

        if (strlen($normalised) > self::MAX_LENGTH) {
            throw InvalidMeterCode::tooLong($normalised);
        }

        return new self($normalised);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
