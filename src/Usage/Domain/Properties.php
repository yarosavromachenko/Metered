<?php

declare(strict_types=1);

namespace Metered\Usage\Domain;

use Metered\Usage\Domain\Exception\InvalidProperties;

/**
 * The client's own labels on an event: region, tier, whether it was a retry.
 *
 * Flat and small, deliberately. They exist to be filtered and grouped by in
 * the usage explorer, and later to select which price applies; a nested
 * document in a column on the largest table in the system would be neither
 * filterable nor cheap. Anything richer belongs in the client's own logs,
 * with the event id to join on.
 */
final readonly class Properties
{
    public const int MAX_KEYS = 32;

    public const int MAX_VALUE_LENGTH = 256;

    /**
     * @param  array<string, scalar|null>  $values
     */
    private function __construct(private array $values) {}

    /**
     * @param  array<array-key, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        if (count($values) > self::MAX_KEYS) {
            throw InvalidProperties::tooMany(count($values));
        }

        $properties = [];

        foreach ($values as $key => $value) {
            // A JSON list decodes to integer keys, and a list of labels is a
            // request that cannot be stored as one.
            if (! is_string($key) || trim($key) === '') {
                throw InvalidProperties::unnamed();
            }

            if ($value !== null && ! is_scalar($value)) {
                throw InvalidProperties::nested($key);
            }

            if (is_string($value) && strlen($value) > self::MAX_VALUE_LENGTH) {
                throw InvalidProperties::valueTooLong($key);
            }

            $properties[$key] = $value;
        }

        return new self($properties);
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @return array<string, scalar|null>
     */
    public function all(): array
    {
        return $this->values;
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }
}
