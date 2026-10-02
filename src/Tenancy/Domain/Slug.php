<?php

declare(strict_types=1);

namespace Metered\Tenancy\Domain;

use Metered\Tenancy\Domain\Exception\InvalidSlug;
use Stringable;
use Transliterator;

/**
 * Lowercase ASCII letters, digits and single hyphens, at most 63 characters
 * (a DNS label). {@see self::fromName()} derives one from a display name;
 * {@see self::fromString()} only validates.
 */
final readonly class Slug implements Stringable
{
    private const string PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    private const int MIN_LENGTH = 2;

    private const int MAX_LENGTH = 63;

    private function __construct(public string $value) {}

    public function __toString(): string
    {
        return $this->value;
    }

    public static function fromString(string $value): self
    {
        $normalised = strtolower(trim($value));

        if (strlen($normalised) < self::MIN_LENGTH) {
            throw InvalidSlug::tooShort($value);
        }

        if (strlen($normalised) > self::MAX_LENGTH) {
            throw InvalidSlug::tooLong($value);
        }

        if (preg_match(self::PATTERN, $normalised) !== 1) {
            throw InvalidSlug::malformed($value);
        }

        return new self($normalised);
    }

    public static function fromName(string $name): self
    {
        $latin = self::toLowercaseLatin($name);

        // Other characters become one hyphen; trim after truncating.
        $separated = (string) preg_replace('/[^a-z0-9]+/', '-', $latin);
        $slug = trim(substr($separated, 0, self::MAX_LENGTH), '-');

        if (strlen($slug) < self::MIN_LENGTH) {
            throw InvalidSlug::nothingToSlug($name);
        }

        return self::fromString($slug);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Transliterates to lowercase ASCII: "Überwald GmbH" → "uberwald gmbh".
     */
    private static function toLowercaseLatin(string $name): string
    {
        $transliterator = Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
        $latin = $transliterator?->transliterate($name);

        // ICU returns false on malformed UTF-8; the slug rules then reject it.
        return is_string($latin) ? $latin : strtolower($name);
    }
}
