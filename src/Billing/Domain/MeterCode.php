<?php

declare(strict_types=1);

namespace Metered\Billing\Domain;

use Metered\Billing\Domain\Exception\InvalidMeterCode;
use Stringable;

/**
 * The code events are sent with. Lowercase letters, digits and single
 * separators; normalised to lowercase so `API.Calls` and `api.calls` are one
 * meter. ({@see CustomerReference} keeps its case.)
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

        // Shape before length, so "a" gets the length error.
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
