<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Plan;

use Metered\Billing\Domain\Exception\InvalidPlanCode;
use Stringable;

/**
 * E.g. `pro`, `team-annual`. Unique per project and immutable. No dots, unlike
 * meter codes.
 */
final readonly class PlanCode implements Stringable
{
    private const string PATTERN = '/^[a-z0-9]+([_-][a-z0-9]+)*$/';

    private const int MIN_LENGTH = 2;

    private const int MAX_LENGTH = 64;

    private function __construct(public string $value) {}

    public function __toString(): string
    {
        return $this->value;
    }

    public static function fromString(string $value): self
    {
        $normalised = strtolower(trim($value));

        if (preg_match(self::PATTERN, $normalised) !== 1) {
            throw InvalidPlanCode::malformed($value);
        }

        if (strlen($normalised) < self::MIN_LENGTH) {
            throw InvalidPlanCode::tooShort($normalised);
        }

        if (strlen($normalised) > self::MAX_LENGTH) {
            throw InvalidPlanCode::tooLong($normalised);
        }

        return new self($normalised);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
