<?php

declare(strict_types=1);

namespace Metered\Tenancy\Domain;

use Metered\Tenancy\Domain\Exception\InvalidSlug;
use Stringable;
use Transliterator;

/**
 * The name an organization or a project is addressed by.
 *
 * Slugs appear in URLs, in the panel's project switcher and in seeded demo
 * data, so they are constrained to what survives all three: lowercase ASCII,
 * digits, and single hyphens between them. The upper bound is 63 characters,
 * the length of a DNS label — the system does not use subdomains today, and
 * choosing a limit that forecloses them costs nothing.
 *
 * {@see self::fromName()} is the path a human takes: they type "Acme, Inc."
 * and the slug is derived. {@see self::fromString()} is the path a stored or
 * explicitly chosen slug takes, and it validates rather than repairs — a slug
 * read back from the database in the wrong shape is a bug to see, not to fix
 * silently.
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

        // Every run of anything else becomes one separator, which is what
        // turns "North Wind  Billing" and "Acme, Inc." into single hyphens.
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $latin), '-');

        // Truncation can leave a trailing hyphen, so trim again rather than
        // handing fromString() a value it would reject.
        $slug = trim(substr($slug, 0, self::MAX_LENGTH), '-');

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
     * Folds accents and non-Latin scripts down to lowercase ASCII, so that
     * "Überwald GmbH" becomes "uberwald gmbh" instead of losing the character
     * altogether. Without the fold, a name written in one script would slug to
     * nothing at all, and the sign-up form would reject it with no explanation
     * a person could act on.
     */
    private static function toLowercaseLatin(string $name): string
    {
        $transliterator = Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
        $latin = $transliterator?->transliterate($name);

        // ICU returns false on malformed UTF-8 rather than throwing. Falling
        // back to the raw name keeps the failure inside the slug rules, which
        // reject it with a message about the name instead of about encoding.
        return is_string($latin) ? $latin : strtolower($name);
    }
}
